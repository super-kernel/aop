<?php

declare(strict_types=1);

namespace SuperKernel\Aop\Tests\Integration;

use InvalidArgumentException;
use LogicException;
use PhpParser\Error;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use RuntimeException;
use stdClass;
use SuperKernel;
use SuperKernel\Annotator\Model\AttributeMetadata;
use SuperKernel\Aop\Attribute\Aspect;
use SuperKernel\Aop\AutoLoader;
use SuperKernel\Aop\Collector\AspectCollector;
use SuperKernel\Aop\Contract\AspectInterface;
use SuperKernel\Aop\Listener\BeforeApplicationStartListener;
use SuperKernel\Aop\Mutation\Mutator;
use SuperKernel\Aop\Runtime\Dispatcher;
use SuperKernel\Aop\Runtime\ProceedingJoinPoint;
use SuperKernel\Aop\Runtime\SourceMetadata;
use SuperKernel\Aop\Tests\Fixture\RecordingAspect;
use SuperKernel\Aop\Tests\Fixture\SelectedService;
use SuperKernel\Aop\Tests\Fixture\Subject;
use SuperKernel\Aop\Tests\Fixture\UnmarkedService;
use SuperKernel\Autoloader\AutoLoader as AutoloaderAutoLoader;
use SuperKernel\Contract\AnnotationCollectorInterface;
use SuperKernel\Contract\ApplicationInterface;
use SuperKernel\Contract\AutoLoaderInterface;
use SuperKernel\Contract\PackageManagerInterface;
use SuperKernel\Contract\VendorDirInterface;
use SuperKernel\Event\BeforeApplicationStart;
use SuperKernel\Mimic\Driver\FileDriver;
use SuperKernel\Mimic\Driver\StreamDriver;
use SuperKernel\Mimic\MimicFactory;
use Throwable;

use function array_map;
use function array_slice;
use function class_exists;
use function dirname;
use function file_put_contents;
use function glob;
use function is_dir;
use function is_file;
use function mkdir;
use function rmdir;
use function spl_autoload_functions;
use function spl_autoload_register;
use function spl_autoload_unregister;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AutoLoaderTest extends TestCase
{
    /** @var list<callable> */
    private array $originalStack;

    private string $directory;

    protected function setUp(): void
    {
        $this->originalStack = spl_autoload_functions();
        $directory = tempnam(sys_get_temp_dir(), 'aop-drivers-');
        $this->assertIsString($directory);
        unlink($directory);
        mkdir($directory);
        $this->directory = $directory;
    }

    protected function tearDown(): void
    {
        $cache = $this->directory . '/.cache/mimic';

        if (is_dir($cache)) {
            foreach (glob($cache . '/*') ?: [] as $file) {
                unlink($file);
            }

            rmdir($cache);
        }

        if (is_dir($this->directory . '/.cache')) {
            rmdir($this->directory . '/.cache');
        } elseif (is_file($this->directory . '/.cache')) {
            unlink($this->directory . '/.cache');
        }

        rmdir($this->directory);
        foreach (spl_autoload_functions() as $loader) {
            spl_autoload_unregister($loader);
        }

        foreach ($this->originalStack as $loader) {
            spl_autoload_register($loader);
        }
    }

    /** @throws Throwable When aspect transformation or its callback fails */
    #[DataProvider('drivers')]
    public function testCollectedAspectLoadsTransformedMethodsFromTheSharedMap(bool $stream): void
    {
        if ($stream) {
            file_put_contents($this->directory . '/.cache', 'blocked');
        }

        $shared = new AutoloaderAutoLoader([
            SuperKernel\Aop\Tests\Fixture\SelectedService::class => $this->selectedServiceFile(),
        ]);
        $mimic = $this->mimic();
        Dispatcher::intercept([
            SuperKernel\Aop\Tests\Fixture\SelectedService::class,
            'value',
        ], static fn(ProceedingJoinPoint $point): int => 2 * $point->proceed());
        $loader = new AutoLoader($this->aspects([
            SuperKernel\Aop\Tests\Fixture\SelectedService::class,
        ]), $shared, $mimic, $this->unusedContainer(), $this->sources());
        spl_autoload_register($shared, true, true);
        $loader->register();

        $this->assertFalse(class_exists(SelectedService::class, false));
        $this->assertSame(8, new SelectedService()->value(3));
        $source = new ReflectionClass(SelectedService::class)->getFileName();
        $this->assertIsString($source);
        $this->assertStringStartsWith($stream ? 'mimic://' : $this->directory . '/.cache/mimic/', $source);
        $this->assertSame([], $shared->getClassMap());
        $this->assertSame($loader, spl_autoload_functions()[0]);
    }

    /** @throws Throwable When startup registration, target loading, or advice fails */
    #[DataProvider('drivers')]
    public function testTargetLoadingResolvesOnlyItsMatchingAdviceAfterStartup(bool $stream): void
    {
        if ($stream) {
            file_put_contents($this->directory . '/.cache', 'blocked');
        }

        $attribute = new Aspect(
            classes: [
                SuperKernel\Aop\Tests\Fixture\UnmarkedService::class,
            ],
            methods: [
                '\\' . SelectedService::class => [
                    'value',
                ],
            ],
        );
        $aspects = $this->declarations([
            SuperKernel\Aop\Tests\Fixture\RecordingAspect::class => $attribute,
            'Acme\UnrelatedAspect'                               => new SuperKernel\Aop\Attribute\Aspect(
                methods: [
                    'Acme\UnrelatedTarget' => [
                        'run',
                    ],
                ],
                propertyHooks: [
                    'Acme\UnrelatedTarget' => [
                        'value' => [
                            'get',
                        ],
                    ],
                ],
            ),
        ]);
        $shared = new AutoloaderAutoLoader([
            SuperKernel\Aop\Tests\Fixture\SelectedService::class => $this->selectedServiceFile(),
            SuperKernel\Aop\Tests\Fixture\UnmarkedService::class => dirname(__DIR__) . '/Fixture/UnmarkedService.php',
        ]);
        $handler = new RecordingAspect();
        $container = $this->createMock(ContainerInterface::class);
        $loader = new AutoLoader($aspects, $shared, $this->mimic(), $container, $this->sources());
        $resolved = [];
        $container->expects($this->exactly(4))->method('get')->willReturnCallback(
            function (string $id) use ($loader, $handler, &$resolved): object {
                if (AutoLoader::class === $id) {
                    return $loader;
                }

                $this->assertSame(RecordingAspect::class, $id);
                $this->assertSame($loader, spl_autoload_functions()[0]);
                $resolved[] = $id;

                return $handler;
            },
        );
        $listener = new BeforeApplicationStartListener($aspects, $container);
        $event = new BeforeApplicationStart($this->createStub(ApplicationInterface::class));

        $listener->process($event);
        $listener->process($event);

        $this->assertSame([], $resolved);
        $this->assertFalse(class_exists(SelectedService::class, false));
        $this->assertFalse(class_exists(UnmarkedService::class, false));
        $this->assertSame(4, new SelectedService()->value(3));
        $this->assertCount(1, $resolved);
        $this->assertSame(6, new UnmarkedService()->value(5));
        $loader(SelectedService::class);
        $this->assertCount(2, $resolved);
        $this->assertSame([
            'before value',
            'after value',
            'before value',
            'after value',
        ], $handler->events);
        $this->assertSame(8, Subject::double(4));
        $this->assertCount(4, $handler->events);
        $source = new ReflectionClass(SelectedService::class)->getFileName();
        $this->assertIsString($source);
        $this->assertStringStartsWith($stream ? 'mimic://' : $this->directory . '/.cache/mimic/', $source);
        $this->assertSame([], $shared->getClassMap());
    }

    /** @throws Throwable When target loading or handler validation fails */
    public function testInvalidHandlerFailsDuringLoadingBeforeTheTargetIsDeclared(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->expects($this->once())->method('get')->with(RecordingAspect::class)->willReturn(new stdClass());
        $loader = $this->declaredLoader(new Aspect(methods: [
            SuperKernel\Aop\Tests\Fixture\SelectedService::class => [
                'value',
            ],
        ]), $container);
        $loader->register();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains(AspectInterface::class);

        try {
            class_exists(SelectedService::class);
        } finally {
            $this->assertFalse(class_exists(SelectedService::class, false));
        }
    }

    /** @throws Throwable When target loading rejects unsupported property hook advice */
    public function testPropertyHookAdviceFailsOnlyWhenItsTargetIsLoaded(): void
    {
        $loader = $this->declaredLoader(new Aspect(propertyHooks: [
            SuperKernel\Aop\Tests\Fixture\SelectedService::class => [
                'value' => [
                    'get',
                ],
            ],
        ]), $this->unusedContainer());
        $loader->register();
        $this->assertFalse(class_exists(SelectedService::class, false));
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs('AOP property hook interception is not implemented.');

        try {
            class_exists(SelectedService::class);
        } finally {
            $this->assertFalse(class_exists(SelectedService::class, false));
        }
    }

    /** @throws Throwable When a retry cannot resolve its handler or load its target */
    public function testHandlerResolutionFailurePreservesTheFailureAndAllowsLoadingToBeRetried(): void
    {
        $failure = new RuntimeException('Handler resolution failed.');
        $handler = new RecordingAspect();
        $attempts = 0;
        $container = $this->createMock(ContainerInterface::class);
        $container->expects($this->exactly(2))->method('get')->with(RecordingAspect::class)->willReturnCallback(
            static function () use ($failure, $handler, &$attempts): RecordingAspect {
                if (1 === ++$attempts) {
                    throw $failure;
                }

                return $handler;
            },
        );
        $loader = $this->declaredLoader(new Aspect(methods: [
            SuperKernel\Aop\Tests\Fixture\SelectedService::class => [
                'value',
            ],
        ]), $container);
        $loader->register();

        try {
            class_exists(SelectedService::class);
            $this->fail('Handler resolution must fail before declaring the target.');
        } catch (RuntimeException $caught) {
            $this->assertSame($failure, $caught);
            $this->assertFalse(class_exists(SelectedService::class, false));
        }

        $this->assertSame(4, new SelectedService()->value(3));
        $this->assertSame([
            'before value',
            'after value',
        ], $handler->events);
    }

    /** @throws Throwable When matching handlers or the selected target cannot be loaded */
    public function testLastMatchingHandlerWinsForCaseInsensitiveSelectors(): void
    {
        $first = new RecordingAspect();
        $last = new RecordingAspect();
        $aspects = $this->declarations([
            'Acme\FirstAspect' => new SuperKernel\Aop\Attribute\Aspect(methods: [
                SuperKernel\Aop\Tests\Fixture\SelectedService::class => [
                    'VALUE',
                    'value',
                ],
            ]),
            'Acme\LastAspect' => new SuperKernel\Aop\Attribute\Aspect(methods: [
                SuperKernel\Aop\Tests\Fixture\SelectedService::class => [
                    'VALUE',
                ],
            ]),
        ]);
        $container = $this->createMock(ContainerInterface::class);
        $container->expects($this->exactly(2))->method('get')->willReturnMap([
            [
                'Acme\FirstAspect',
                $first,
            ],
            [
                'Acme\LastAspect',
                $last,
            ],
        ]);
        $shared = new AutoloaderAutoLoader([
            SuperKernel\Aop\Tests\Fixture\SelectedService::class => $this->selectedServiceFile(),
        ]);
        $loader = new AutoLoader($aspects, $shared, $this->mimic(), $container, $this->sources());
        $loader->register();

        $this->assertSame(4, new SelectedService()->value(3));
        $this->assertSame([], $first->events);
        $this->assertSame([
            'before value',
            'after value',
        ], $last->events);
    }

    /** @throws Throwable When Mimic initialization or native fixture loading fails */
    public function testUnmarkedClassFallsThroughWithoutReadingTheSharedMap(): void
    {
        $shared = $this->createMock(AutoLoaderInterface::class);
        $shared->expects($this->never())->method('getClassMap');
        $mimic = $this->mimic();
        Dispatcher::intercept(
            UnmarkedService::class,
            static fn(ProceedingJoinPoint $point): int => $point->proceed() + 100,
        );
        $loader = new AutoLoader($this->aspects([
            SuperKernel\Aop\Tests\Fixture\SelectedService::class,
        ]), $shared, $mimic, $this->unusedContainer(), $this->sources());
        $loader->register();

        $this->assertSame(4, new UnmarkedService()->value(3));
        $this->assertDirectoryDoesNotExist($this->directory . '/.cache/mimic');
    }

    /** @throws Throwable When Mimic initialization fails */
    public function testCollectedClassWithoutAPendingPathFallsThrough(): void
    {
        $class = 'SuperKernel\\Aop\\Tests\\MissingAspect';
        $shared = $this->createMock(AutoLoaderInterface::class);
        $shared->expects($this->once())->method('getClassMap')->willReturn([]);
        $loader = new AutoLoader($this->aspects([
            $class,
        ]), $shared, $this->mimic(), $this->unusedContainer(), $this->sources());
        $observed = [];
        spl_autoload_register(static function (string $requested) use (&$observed): void {
            $observed[] = $requested;
        });
        $loader->register();

        $this->assertFalse(class_exists($class));
        $this->assertSame([
            $class,
        ], $observed);
        $this->assertDirectoryDoesNotExist($this->directory . '/.cache/mimic');
    }

    /** @throws Throwable When Mimic initialization or native fixture loading fails */
    public function testAlreadyLoadedAspectIsNotRegisteredWithMimic(): void
    {
        $shared = new AutoloaderAutoLoader([
            SuperKernel\Aop\Tests\Fixture\SelectedService::class => $this->selectedServiceFile(),
        ]);
        $this->assertTrue(class_exists(SelectedService::class));
        $loader = new AutoLoader($this->aspects([
            SuperKernel\Aop\Tests\Fixture\SelectedService::class,
        ]), $shared, $this->mimic(), $this->unusedContainer(), $this->sources());

        $loader(SelectedService::class);

        $this->assertSame([], $shared->getClassMap());
        $this->assertDirectoryDoesNotExist($this->directory . '/.cache/mimic');
        $this->assertSame(4, new SelectedService()->value(3));
    }

    /** @throws Throwable When Mimic initialization fails */
    public function testRegistrationPrependsOneCallbackAndPreservesExistingLoaders(): void
    {
        $before = spl_autoload_functions();
        $loader = new AutoLoader(
            $this->aspects(),
            $this->createStub(AutoLoaderInterface::class),
            $this->mimic(),
            $this->unusedContainer(),
            $this->sources(),
        );
        $this->assertSame($before, spl_autoload_functions());

        $loader->register();
        $loader->register();

        $this->assertSame($loader, spl_autoload_functions()[0]);
        $this->assertSame($before, array_slice(spl_autoload_functions(), 1));
    }

    /** @throws Throwable When source preparation or Mimic initialization fails */
    public function testParseFailuresPropagateWithoutLoadingUntransformedSource(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'super-kernel-aop-');
        $this->assertIsString($file);
        $class = 'SuperKernel\\Aop\\Tests\\BrokenAspect';

        try {
            $this->assertNotFalse(file_put_contents($file, '<?php class BrokenAspect { public function broken( }'));
            $shared = $this->createStub(AutoLoaderInterface::class);
            $shared->method('getClassMap')->willReturn([
                $class => $file,
            ]);
            $loader = new AutoLoader($this->aspects([
                $class,
            ]), $shared, $this->mimic(), $this->unusedContainer(), $this->sources());
            $fallback = [];
            spl_autoload_register(static function (string $requested) use (&$fallback, $class): void {
                if ($class === $requested) {
                    $fallback[] = $requested;
                }
            });
            $loader->register();

            try {
                class_exists($class);
                $this->fail('Invalid aspect source must raise the parser error.');
            } catch (Error) {
                $this->assertSame([], $fallback);
                $this->assertFalse(class_exists($class, false));
            }
        } finally {
            unlink($file);
        }
    }

    /** @throws Throwable When the mapped source cannot be prepared */
    public function testPreparedSourceMustDeclareTheRequestedSymbol(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'aop-missing-symbol-');
        $this->assertIsString($file);
        $class = 'SuperKernel\\Aop\\Tests\\MissingDeclaration';

        try {
            file_put_contents($file, '<?php');
            $shared = $this->createStub(AutoLoaderInterface::class);
            $shared->method('getClassMap')->willReturn([
                $class => $file,
            ]);
            $loader = new AutoLoader($this->aspects([
                $class,
            ]), $shared, $this->mimic(), $this->unusedContainer(), $this->sources([
                $class => $file,
            ]));
            $loader->register();
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageIs('The mapped source did not declare ' . $class . '.');

            class_exists($class);
        } finally {
            unlink($file);
        }
    }

    /** @return array<string, array{bool}> */
    public static function drivers(): array
    {
        return [
            'file' => [
                false,
            ],
            'stream fallback' => [
                true,
            ],
        ];
    }

    private function mimic(): MimicFactory
    {
        $vendor = $this->createStub(VendorDirInterface::class);
        $vendor->method('__toString')->willReturn($this->directory);
        $mutator = new Mutator();

        return new MimicFactory(new FileDriver($vendor, $mutator, $this->packages()), new StreamDriver($mutator));
    }

    /** @param list<class-string> $classes */
    private function aspects(array $classes = []): AspectCollector
    {
        $aspects = [];

        foreach ($classes as $class) {
            $aspects[$class] = new Aspect();
        }

        return $this->declarations($aspects);
    }

    /** @param array<class-string, Aspect> $aspects */
    private function declarations(array $aspects): AspectCollector
    {
        $collector = $this->createStub(AnnotationCollectorInterface::class);
        $collector->method('getClassesWithAttribute')->willReturn(array_map(
            static fn(Aspect $aspect): array => [
                new AttributeMetadata($aspect),
            ],
            $aspects,
        ));

        return new AspectCollector($collector);
    }

    private function declaredLoader(Aspect $aspect, ContainerInterface $container): AutoLoader
    {
        return new AutoLoader(
            $this->declarations([
                SuperKernel\Aop\Tests\Fixture\RecordingAspect::class => $aspect,
            ]),
            new AutoloaderAutoLoader([
                SuperKernel\Aop\Tests\Fixture\SelectedService::class => $this->selectedServiceFile(),
            ]),
            $this->mimic(),
            $container,
            $this->sources(),
        );
    }

    /** @param array<class-string, non-empty-string>|null $classes */
    private function packages(?array $classes = null): PackageManagerInterface
    {
        $packages = $this->createStub(PackageManagerInterface::class);
        $packages->method('allClasses')->willReturn($classes ?? [
            SuperKernel\Aop\Tests\Fixture\SelectedService::class => $this->selectedServiceFile(),
            SuperKernel\Aop\Tests\Fixture\UnmarkedService::class => dirname(__DIR__) . '/Fixture/UnmarkedService.php',
        ]);

        return $packages;
    }

    /** @param array<class-string, non-empty-string>|null $classes */
    private function sources(?array $classes = null): SourceMetadata
    {
        return new SourceMetadata($this->packages($classes));
    }

    private function unusedContainer(): ContainerInterface
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->expects($this->never())->method('get');

        return $container;
    }

    /** @return non-empty-string */
    private function selectedServiceFile(): string
    {
        return dirname(__DIR__) . '/Fixture/SelectedService.php';
    }
}
