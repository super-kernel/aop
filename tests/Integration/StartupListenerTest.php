<?php

declare(strict_types=1);

namespace SuperKernel\Aop\Tests\Integration;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\ListenerProviderInterface;
use ReflectionClass;
use stdClass;
use SuperKernel;
use SuperKernel\Annotator\Model\AttributeMetadata;
use SuperKernel\Aop\Attribute\Aspect;
use SuperKernel\Aop\AutoLoader;
use SuperKernel\Aop\Collector\AspectCollector;
use SuperKernel\Aop\Listener\BeforeApplicationStartListener;
use SuperKernel\Aop\Mutation\Mutator;
use SuperKernel\Aop\Runtime\SourceMetadata;
use SuperKernel\Aop\Tests\Fixture\FollowingStartupListener;
use SuperKernel\Aop\Tests\Fixture\RecordingAspect;
use SuperKernel\Aop\Tests\Fixture\SelectedService;
use SuperKernel\Aop\Tests\Fixture\UnmarkedService;
use SuperKernel\Attribute\Listener;
use SuperKernel\Contract\AnnotationCollectorInterface;
use SuperKernel\Contract\ApplicationInterface;
use SuperKernel\Contract\AutoLoaderInterface;
use SuperKernel\Contract\PackageManagerInterface;
use SuperKernel\Contract\VendorDirInterface;
use SuperKernel\Event\BeforeApplicationStart;
use SuperKernel\EventDispatcher\EventDispatcher;
use SuperKernel\EventDispatcher\Provider\ListenerProviderProvider;
use SuperKernel\Mimic\Driver\FileDriver;
use SuperKernel\Mimic\Driver\StreamDriver;
use SuperKernel\Mimic\MimicFactory;
use TypeError;

use function array_slice;
use function class_exists;
use function iterator_to_array;
use function spl_autoload_functions;
use function spl_autoload_register;
use function spl_autoload_unregister;
use function sys_get_temp_dir;

use const PHP_INT_MAX;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class StartupListenerTest extends TestCase
{
    /** @throws ContainerExceptionInterface when a test listener cannot be resolved */
    public function testDiscoveredStartupListenerRunsInTheHighestPriorityGroup(): void
    {
        $listener = $this->emptyListener();
        $following = new FollowingStartupListener();
        $provider = $this->provider($listener, $following, PHP_INT_MAX - 1);
        $application = $this->createStub(ApplicationInterface::class);
        $event = new BeforeApplicationStart($application);
        $callbacks = iterator_to_array($provider->getListenersForEvent($event), false);

        $this->assertCount(2, $callbacks);
        $this->assertSame($listener, $callbacks[0][0]);
        $this->assertSame($following, $callbacks[1][0]);

        $dispatcher = new EventDispatcher($provider);
        $this->assertSame($event, $dispatcher->dispatch($event));
        $this->assertSame($event, $dispatcher->dispatch($event));
        $this->assertSame($application, $event->application());
        $this->assertSame([
            $event,
            $event,
        ], $following->events);
        $this->assertSame([], iterator_to_array($provider->getListenersForEvent(new stdClass()), false));
    }

    /** @throws ContainerExceptionInterface when a test listener cannot be resolved */
    public function testEqualMaximumPrioritiesPreserveDiscoveryOrder(): void
    {
        $listener = $this->emptyListener();
        $peer = new FollowingStartupListener();
        $provider = $this->provider($listener, $peer, PHP_INT_MAX);
        $event = new BeforeApplicationStart($this->createStub(ApplicationInterface::class));
        $callbacks = iterator_to_array($provider->getListenersForEvent($event), false);

        $this->assertSame($peer, $callbacks[0][0]);
        $this->assertSame($listener, $callbacks[1][0]);
    }

    /** @throws ContainerExceptionInterface When an unexpected service lookup occurs */
    public function testEmptyCollectorLeavesTheSplStackUntouched(): void
    {
        $before = spl_autoload_functions();
        $listener = $this->emptyListener();

        $listener->process(new BeforeApplicationStart($this->createStub(ApplicationInterface::class)));

        $this->assertSame($before, spl_autoload_functions());
    }

    /** @throws ContainerExceptionInterface When the aspect loader cannot be resolved */
    public function testNonEmptyCollectorDefersLoaderCreationUntilStartupAndRegistersItOnce(): void
    {
        $aspects = $this->aspects([
            'Acme\\CollectedAspect',
        ]);
        $shared = $this->createStub(AutoLoaderInterface::class);
        $vendor = $this->createStub(VendorDirInterface::class);
        $vendor->method('__toString')->willReturn(sys_get_temp_dir());
        $loader = null;
        $packages = $this->createMock(PackageManagerInterface::class);
        $packages->expects($this->never())->method('allClasses');
        $sources = new SourceMetadata($packages);
        $container = $this->createMock(ContainerInterface::class);
        $container->expects($this->exactly(2))->method('get')->with(AutoLoader::class)->willReturnCallback(
            static function () use ($aspects, $shared, $vendor, $container, $packages, $sources, &$loader): AutoLoader {
                $mutator = new Mutator();

                return $loader ??= new AutoLoader($aspects, $shared, new MimicFactory(
                    new FileDriver($vendor, $mutator, $packages),
                    new StreamDriver($mutator),
                ), $container, $sources);
            },
        );
        $before = spl_autoload_functions();
        $listener = new BeforeApplicationStartListener($aspects, $container);
        $event = new BeforeApplicationStart($this->createStub(ApplicationInterface::class));
        $this->assertNull($loader);
        $this->assertSame($before, spl_autoload_functions());

        try {
            $listener->process($event);
            $listener->process($event);

            $this->assertInstanceOf(AutoLoader::class, $loader);
            $this->assertSame($loader, spl_autoload_functions()[0]);
            $this->assertSame($before, array_slice(spl_autoload_functions(), 1));
        } finally {
            foreach (spl_autoload_functions() as $registered) {
                spl_autoload_unregister($registered);
            }

            foreach ($before as $registered) {
                spl_autoload_register($registered);
            }
        }
    }

    /** @throws ContainerExceptionInterface When the aspect loader cannot be resolved */
    public function testStartupDoesNotResolveOrValidateConfiguredAdvice(): void
    {
        $collector = $this->createStub(AnnotationCollectorInterface::class);
        $collector->method('getClassesWithAttribute')->willReturn([
            SuperKernel\Aop\Tests\Fixture\RecordingAspect::class => [
                new SuperKernel\Annotator\Model\AttributeMetadata(new SuperKernel\Aop\Attribute\Aspect(
                    methods: [
                        SuperKernel\Aop\Tests\Fixture\SelectedService::class => [
                            'value',
                        ],
                    ],
                    propertyHooks: [
                        SuperKernel\Aop\Tests\Fixture\UnmarkedService::class => [
                            'value' => [
                                'get',
                            ],
                        ],
                    ],
                )),
            ],
        ]);
        $aspects = new AspectCollector($collector);
        $vendor = $this->createStub(VendorDirInterface::class);
        $vendor->method('__toString')->willReturn(sys_get_temp_dir());
        $mutator = new Mutator();
        $shared = $this->createMock(AutoLoaderInterface::class);
        $shared->expects($this->never())->method('getClassMap');
        $container = $this->createMock(ContainerInterface::class);
        $packages = $this->createMock(PackageManagerInterface::class);
        $packages->expects($this->never())->method('allClasses');
        $loader = new AutoLoader(
            $aspects,
            $shared,
            new MimicFactory(new FileDriver($vendor, $mutator, $packages), new StreamDriver($mutator)),
            $container,
            new SourceMetadata($packages),
        );
        $container->expects($this->exactly(2))->method('get')->with(AutoLoader::class)->willReturn($loader);
        $listener = new BeforeApplicationStartListener($aspects, $container);
        $event = new BeforeApplicationStart($this->createStub(ApplicationInterface::class));

        try {
            $listener->process($event);
            $listener->process($event);

            $this->assertSame($loader, spl_autoload_functions()[0]);
            $this->assertFalse(class_exists(RecordingAspect::class, false));
            $this->assertFalse(class_exists(SelectedService::class, false));
            $this->assertFalse(class_exists(UnmarkedService::class, false));
        } finally {
            spl_autoload_unregister($loader);
        }
    }

    /** @throws ContainerExceptionInterface When the aspect loader cannot be resolved */
    public function testWrongPsrLoaderFailsAtTheTypedActivationBoundary(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->expects(self::once())->method('get')->with(AutoLoader::class)->willReturn(new stdClass());
        $listener = new BeforeApplicationStartListener($this->aspects([
            'Acme\\CollectedAspect',
        ]), $container);

        $this->expectException(TypeError::class);
        $this->expectExceptionMessageIsOrContains('AutoLoaderResolution::resolve()');

        $listener->process(new BeforeApplicationStart($this->createStub(ApplicationInterface::class)));
    }

    private function emptyListener(): BeforeApplicationStartListener
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->expects($this->never())->method('get');

        return new BeforeApplicationStartListener($this->aspects(), $container);
    }

    /** @param list<class-string> $classes */
    private function aspects(array $classes = []): AspectCollector
    {
        $metadata = [];

        foreach ($classes as $class) {
            $metadata[$class] = [
                new AttributeMetadata(new Aspect()),
            ];
        }

        $collector = $this->createStub(AnnotationCollectorInterface::class);
        $collector->method('getClassesWithAttribute')->willReturn($metadata);

        return new AspectCollector($collector);
    }

    /** @throws ContainerExceptionInterface when a test listener cannot be resolved */
    private function provider(
        BeforeApplicationStartListener $listener,
        FollowingStartupListener       $peer,
        int                            $peerPriority,
    ): ListenerProviderInterface
    {
        $reflection = new ReflectionClass($listener);
        $attributes = $reflection->getAttributes(Listener::class);
        $this->assertCount(1, $attributes);
        $attribute = $attributes[0]->newInstance();
        $this->assertSame(PHP_INT_MAX, $attribute->priority);
        $collector = $this->createMock(AnnotationCollectorInterface::class);
        $collector->expects($this->once())->method('getClassesWithAttribute')->with(Listener::class)->willReturn([
            SuperKernel\Aop\Tests\Fixture\FollowingStartupListener::class => [
                new SuperKernel\Annotator\Model\AttributeMetadata(new SuperKernel\Attribute\Listener($peerPriority)),
            ],
            SuperKernel\Aop\Listener\BeforeApplicationStartListener::class => [
                new SuperKernel\Annotator\Model\AttributeMetadata($attribute),
            ],
        ]);
        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')->willReturnMap([
            [
                SuperKernel\Aop\Listener\BeforeApplicationStartListener::class,
                $listener,
            ],
            [
                SuperKernel\Aop\Tests\Fixture\FollowingStartupListener::class,
                $peer,
            ],
        ]);

        return new ListenerProviderProvider($container)($collector);
    }
}
