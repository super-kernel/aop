<?php

declare(strict_types=1);

namespace SuperKernel\Aop\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use SuperKernel\Aop\Contract\ProceedingJoinPointInterface;
use SuperKernel\Aop\Mutation\Mutator;
use SuperKernel\Aop\Runtime\Dispatcher;
use SuperKernel\Aop\Runtime\ProceedingJoinPoint;
use SuperKernel\Aop\Runtime\SourceMetadata;
use SuperKernel\Aop\Runtime\Tracker;
use SuperKernel\Aop\Tests\Fixture\Subject;
use SuperKernel\Aop\Tests\Fixture\VendorDirectory;
use SuperKernel\Contract\PackageManagerInterface;
use SuperKernel\Mimic\Driver\FileDriver;
use SuperKernel\Mimic\Driver\StreamDriver;
use SuperKernel\Mimic\MimicFactory;
use SuperKernel\Mimic\Source\SourceFile;
use Throwable;

use function array_map;
use function array_search;
use function dirname;
use function file;
use function file_get_contents;
use function restore_exception_handler;
use function set_exception_handler;
use function spl_autoload_functions;
use function strtolower;
use function trim;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class InterceptionTest extends TestCase
{
    /** @throws Throwable When the fixture cannot be prepared or executed */
    #[DataProvider('drivers')]
    public function testNativeConstructionPreservesFinalPrivateAndPromotedMembers(bool $stream): void
    {
        $this->mimic($stream);
        $subject = new Subject(4);

        $this->assertSame(12, $subject->multiply(3));
        $this->assertTrue($subject->sameToken);
        $reflection = new ReflectionClass($subject);
        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->getMethod('multiply')->isFinal());
        $this->assertTrue($reflection->getMethod('scale')->isPrivate());
        $this->assertTrue($reflection->getProperty('factor')->isReadOnly());
        $this->assertSame([
            $this->fixture(),
            dirname($this->fixture()),
        ], $subject->source());
        $this->assertSame($this->fixture(), $reflection->getConstant('SOURCE_FILE'));
        $this->assertSame(dirname($this->fixture()), $subject->sourceDirectory);
        $this->assertSame($this->fixture(), $subject->defaultSource());
    }

    /** @throws Throwable */
    #[DataProvider('drivers')]
    public function testMethodSelectorOverridesClassSelectorAndCanProceedIntoPrivateMethods(bool $stream): void
    {
        $calls = [];
        $this->mimic($stream);
        Dispatcher::intercept(Subject::class, static function (
            ProceedingJoinPoint $point,
        ) use (&$calls): mixed {
            $calls[] = $point->getShortMethodName();

            return $point->proceed();
        });
        Dispatcher::intercept([
            Subject::class,
            'scale',
        ], static fn(ProceedingJoinPoint $point): int => $point->proceed() + 1);
        Dispatcher::intercept(strtolower(Subject::class) . '::MULTIPLY', function (
            ProceedingJoinPointInterface $point,
        ): int {
            $this->assertInstanceOf(Subject::class, $point->getInstance());
            $this->assertSame([
                3,
            ], $point->getArguments());
            $this->assertSame('multiply', $point->getMethodReflection()->getName());
            $this->assertSame(Subject::class, $point->getClassReflection()->getName());

            return $point->proceed() * 10;
        });

        $this->assertSame(70, new Subject()->multiply(3));
        $this->assertSame([
            '__construct',
        ], $calls);
    }

    /** @throws Throwable */
    #[DataProvider('drivers')]
    public function testStaticMethodSuppliesAClassNameAndSupportsProceed(bool $stream): void
    {
        $this->mimic($stream);
        Dispatcher::intercept(Subject::class . '::double', function (ProceedingJoinPointInterface $point): int {
            $this->assertSame(Subject::class, $point->getInstance());
            $this->assertTrue($point->getMethodReflection()->isStatic());
            $this->assertSame(Subject::class, $point->getClassReflection()->getName());

            return $point->proceed() + 1;
        });

        $this->assertSame(9, Subject::double(4));
    }

    /** @throws Throwable */
    #[DataProvider('drivers')]
    public function testVoidMethodDiscardsTheInterceptorResult(bool $stream): void
    {
        $this->mimic($stream);
        Dispatcher::intercept(Subject::class . '::record', static function (ProceedingJoinPoint $point): int {
            $point->proceed();

            return 99;
        });
        $subject = new Subject();
        $subject->record(42);

        $this->assertSame(42, $subject->recorded);
    }

    /** @throws Throwable When the fixture cannot be prepared or executed */
    #[DataProvider('drivers')]
    public function testNeverMethodPreservesTheOriginalFailureLocation(bool $stream): void
    {
        $this->mimic($stream);

        try {
            new Subject()->fail();
        } catch (RuntimeException $exception) {
            $this->assertSame('original failure', $exception->getMessage());
            $this->assertSame($this->fixture(), $exception->getFile());
            $this->assertSame($this->throwLine('original failure'), $exception->getLine());
        }

        $this->assertSame(0, Tracker::$currentLine);
    }

    /** @throws Throwable When the fixture cannot be prepared or executed */
    #[DataProvider('drivers')]
    public function testNestedDispatchRestoresTheCallersDiagnosticLine(bool $stream): void
    {
        $this->mimic($stream);

        try {
            new Subject()->failAfterNestedCall();
        } catch (RuntimeException $exception) {
            $this->assertSame($this->fixture(), $exception->getFile());
            $this->assertSame($this->throwLine('outer failure'), $exception->getLine());
        }

        $this->assertSame(0, Tracker::$currentLine);
    }

    /** @throws Throwable When the fixture cannot be prepared or executed */
    #[DataProvider('drivers')]
    public function testCallbackFailuresKeepTheirOwnFileAndLine(bool $stream): void
    {
        $failure = new RuntimeException('callback failure');
        $line = $failure->getLine();
        $this->mimic($stream);
        Dispatcher::intercept(Subject::class . '::multiply', static function () use ($failure): never {
            throw $failure;
        });

        try {
            new Subject()->multiply(1);
            $this->fail('The callback failure must propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
            $this->assertSame(__FILE__, $exception->getFile());
            $this->assertSame($line, $exception->getLine());
        }
    }

    public function testInitializationPreservesTheExceptionHandlerAndAvoidsDuplicateLoaders(): void
    {
        $handler = static function (Throwable $_exception): void {
        };
        set_exception_handler($handler);

        try {
            $stack = spl_autoload_functions();
            $this->factory();
            $this->factory();
            $this->assertSame($stack, spl_autoload_functions());
            $this->assertSame($handler, set_exception_handler($handler));
            restore_exception_handler();
        } finally {
            restore_exception_handler();
        }
    }

    /** @return array<string, array{bool}> */
    public static function drivers(): array
    {
        return [
            'file' => [
                false,
            ],
            'stream' => [
                true,
            ],
        ];
    }

    private VendorDirectory $vendor;

    protected function setUp(): void
    {
        $this->vendor = new VendorDirectory();
    }

    protected function tearDown(): void
    {
        $this->vendor->remove();
    }

    private function factory(): MimicFactory
    {
        $mutator = new Mutator();

        return new MimicFactory(
            new FileDriver($this->vendor, $mutator, $this->packages()),
            new StreamDriver($mutator),
        );
    }

    /** @throws Throwable When the selected driver cannot prepare the fixture */
    private function mimic(bool $stream): void
    {
        $mimic = $this->factory();
        $path = $stream
            ? new StreamDriver(new Mutator())->prepare(new SourceFile($this->fixture()))
            : $mimic->prepare($this->fixture());
        $code = file_get_contents($path);
        $this->assertIsString($code);
        $this->assertStringNotContainsString($this->fixture(), $code);
        new SourceMetadata($this->packages())->register(Subject::class);
        require_once $path;

    }

    private function packages(): PackageManagerInterface
    {
        $packages = $this->createStub(PackageManagerInterface::class);
        $packages->method('allClasses')->willReturn([
            Subject::class => $this->fixture(),
        ]);

        return $packages;
    }

    private function fixture(): string
    {
        return dirname(__DIR__) . '/Fixture/Subject.php';
    }

    private function throwLine(string $message): int
    {
        $lines = file($this->fixture());
        $this->assertNotFalse($lines);
        $line = array_search("throw new RuntimeException('$message');", array_map(trim(...), $lines), true);
        $this->assertNotFalse($line);

        return $line + 1;
    }
}
