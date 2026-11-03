<?php

declare(strict_types=1);

namespace SuperKernel\Aop\Tests\Integration;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\ListenerProviderInterface;
use stdClass;
use SuperKernel;
use SuperKernel\Annotator\Model\AttributeMetadata;
use SuperKernel\Aop\Attribute\Aspect;
use SuperKernel\Aop\AutoLoader;
use SuperKernel\Aop\Collector\AspectCollector;
use SuperKernel\Aop\Listener\ApplicationBuildListener;
use SuperKernel\Aop\Mutation\Mutator;
use SuperKernel\Aop\Runtime\SourceMetadata;
use SuperKernel\Aop\Tests\Fixture\SelectedService;
use SuperKernel\Aop\Tests\Fixture\UnmarkedService;
use SuperKernel\Aop\Tests\Fixture\VendorDirectory;
use SuperKernel\Attribute\Listener;
use SuperKernel\Autoloader\AutoLoader as AutoloaderAutoLoader;
use SuperKernel\Contract\AnnotationCollectorInterface;
use SuperKernel\Contract\PackageManagerInterface;
use SuperKernel\Event\ApplicationBuild;
use SuperKernel\EventDispatcher\EventDispatcher;
use SuperKernel\EventDispatcher\Provider\ListenerProviderProvider;
use SuperKernel\Mimic\Driver\FileDriver;
use SuperKernel\Mimic\Driver\StreamDriver;
use SuperKernel\Mimic\MimicFactory;
use Throwable;
use TypeError;

use function class_exists;
use function dirname;
use function glob;
use function spl_autoload_functions;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class BuildListenerTest extends TestCase
{
    private VendorDirectory $vendor;

    protected function setUp(): void
    {
        $this->vendor = new VendorDirectory();
    }

    protected function tearDown(): void
    {
        $this->vendor->remove();
    }

    /** @throws Throwable When listener discovery or proxy preparation fails */
    public function testDiscoveredBuildListenerPreparesTargetProxiesWithoutLoadingThem(): void
    {
        $aspects = $this->aspects([
            SuperKernel\Aop\Tests\Fixture\SelectedService::class,
        ]);
        $shared = new AutoloaderAutoLoader([
            SuperKernel\Aop\Tests\Fixture\SelectedService::class => $this->fixture('SelectedService'),
            SuperKernel\Aop\Tests\Fixture\UnmarkedService::class => $this->fixture('UnmarkedService'),
        ]);
        $container = $this->createMock(ContainerInterface::class);
        $listener = new ApplicationBuildListener($aspects, $container);
        $loader = new AutoLoader($aspects, $shared, $this->mimic($shared), $container, $this->sources($shared));
        $container->expects($this->exactly(2))->method('get')->willReturnMap([
            [
                SuperKernel\Aop\Listener\ApplicationBuildListener::class,
                $listener,
            ],
            [
                SuperKernel\Aop\AutoLoader::class,
                $loader,
            ],
        ]);
        $stack = spl_autoload_functions();
        $event = new ApplicationBuild();

        $this->assertSame($event, new EventDispatcher($this->provider($container))->dispatch($event));

        $this->assertSame([
            $this->vendor->path . '/.cache/mimic/SuperKernel.Aop.Tests.Fixture.SelectedService.tpl',
        ], glob($this->vendor->path . '/.cache/mimic/*'));
        $this->assertFalse(class_exists(SelectedService::class, false));
        $this->assertFalse(class_exists(UnmarkedService::class, false));
        $this->assertSame($stack, spl_autoload_functions());
    }

    /** @throws Throwable When an unexpected service lookup or proxy preparation occurs */
    public function testEmptyCollectorResolvesNothing(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->expects($this->never())->method('get');

        new ApplicationBuildListener($this->aspects(), $container)->process(new ApplicationBuild());

        $this->assertSame([], glob($this->vendor->path . '/*'));
    }

    public function testListensOnlyForTheBuildEvent(): void
    {
        $listener = new ApplicationBuildListener($this->aspects(), $this->createStub(ContainerInterface::class));

        $this->assertSame([
            ApplicationBuild::class,
        ], $listener->listen());
    }

    /** @throws Throwable When the aspect loader cannot be resolved or proxy preparation fails */
    public function testWrongPsrLoaderFailsAtTheTypedActivationBoundary(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->expects(self::once())->method('get')->with(AutoLoader::class)->willReturn(new stdClass());
        $listener = new ApplicationBuildListener($this->aspects([
            'Acme\\CollectedAspect',
        ]), $container);

        $this->expectException(TypeError::class);
        $this->expectExceptionMessageIsOrContains('AutoLoaderResolution::resolve()');

        $listener->process(new ApplicationBuild());
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

    private function mimic(AutoloaderAutoLoader $shared): MimicFactory
    {
        $mutator = new Mutator($this->sources($shared));

        return new MimicFactory(
            new FileDriver($this->vendor, $mutator, $this->packages($shared)),
            new StreamDriver($mutator),
        );
    }

    private function sources(AutoloaderAutoLoader $shared): SourceMetadata
    {
        return new SourceMetadata($this->packages($shared));
    }

    private function packages(AutoloaderAutoLoader $shared): PackageManagerInterface
    {
        $packages = $this->createStub(PackageManagerInterface::class);
        $packages->method('allClasses')->willReturn($shared->getClassMap());

        return $packages;
    }

    /** @throws ContainerExceptionInterface When the build listener cannot be resolved */
    private function provider(ContainerInterface $container): ListenerProviderInterface
    {
        $collector = $this->createMock(AnnotationCollectorInterface::class);
        $collector->expects($this->once())->method('getClassesWithAttribute')->with(Listener::class)->willReturn([
            SuperKernel\Aop\Listener\ApplicationBuildListener::class => [
                new SuperKernel\Annotator\Model\AttributeMetadata(new SuperKernel\Attribute\Listener()),
            ],
        ]);

        return new ListenerProviderProvider($container)($collector);
    }

    /** @return non-empty-string */
    private function fixture(string $name): string
    {
        return dirname(__DIR__) . '/Fixture/' . $name . '.php';
    }
}
