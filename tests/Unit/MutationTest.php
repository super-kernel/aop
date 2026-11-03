<?php

declare(strict_types=1);

namespace SuperKernel\Aop\Tests\Unit;

use LogicException;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SuperKernel\Aop\Mutation\Mutator;
use SuperKernel\Aop\Runtime\SourceMetadata;
use SuperKernel\Attribute\Provider;
use SuperKernel\Contract\PackageManagerInterface;
use SuperKernel\Contract\PackageMetadataInterface;
use SuperKernel\Mimic\Contract\MutatorInterface;
use Throwable;

final class MutationTest extends TestCase
{
    public function testProvidesTheMimicMutationContract(): void
    {
        $reflection = new ReflectionClass(Mutator::class);
        $attributes = $reflection->getAttributes(Provider::class);
        $this->assertCount(1, $attributes);
        $contract = $attributes[0]->newInstance()->className;
        $this->assertSame(MutatorInterface::class, $contract);
        $this->assertTrue($reflection->implementsInterface($contract));
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testMethodWeavingUsesTheAopRuntime(): void
    {
        $mutated = $this->mutator()->mutate('<?php class Example { public function run(): int { return 1; } }');

        $this->assertStringContainsString('SuperKernel\\Aop\\Runtime\\Dispatcher::dispatch', $mutated);
        $this->assertStringContainsString('SuperKernel\\Aop\\Runtime\\ProceedingJoinPoint', $mutated);
        $this->assertStringNotContainsString('SuperKernel\\Mimic\\Runtime', $mutated);
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testAbstractMethodsAndInterfacesRemainValidDeclarations(): void
    {
        $source = '<?php interface Contract { public function run(): void; }'
            . ' abstract class Example implements Contract {}';
        $mutated = $this->mutator()->mutate($source);

        $this->assertNotNull(new ParserFactory()->createForHostVersion()->parse($mutated));
        $this->assertStringNotContainsString('Dispatcher', $mutated);
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testUnsupportedMethodSemanticsFailBeforeCodeCanBeLoaded(): void
    {
        foreach ([
            'public function &run(): mixed { return $this->value; }',
            'public function run(int &$value): void {}',
            'public function run(int ...$values): void {}',
            'public function run(): iterable { yield 1; }',
            'public function run(): int { static $value = 0; return ++$value; }',
            'public function run(): string { return __METHOD__; }',
        ] as $method) {
            try {
                $this->mutator()->mutate('<?php class Example { ' . $method . ' }');
                $this->fail('Unsupported semantics must be rejected.');
            } catch (LogicException $exception) {
                $this->assertStringContainsString('AOP', $exception->getMessage());
            }
        }
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testSourceConstantsDependOnlyOnThePackageRelativeLocation(): void
    {
        $source = '<?php class Example { public const string FILE = __FILE__; '
            . 'public function directory(): string { return __DIR__; } }';
        $first = $this->mutator('/first/app')->mutate($source, '/first/app/src/Example.php');
        $moved = $this->mutator('/second/app')->mutate($source, '/second/app/src/Example.php');
        $renamed = $this->mutator('/first/app', 'lib')->mutate($source, '/first/app/lib/Example.php');

        $this->assertSame($first, $moved);
        $this->assertNotSame($first, $renamed);
        $this->assertStringNotContainsString('__FILE__', $first);
        $this->assertStringNotContainsString('__DIR__', $first);
        $this->assertStringNotContainsString('/first/app', $first);
    }

    private function mutator(?string $directory = null, string $sourceDirectory = 'src'): Mutator
    {
        $packages = $this->createStub(PackageManagerInterface::class);

        if (null !== $directory) {
            $package = $this->createStub(PackageMetadataInterface::class);
            $package->method('name')->willReturn('acme/app');
            $package->method('locatedPath')->willReturn($directory);
            $packages->method('allClasses')->willReturn([
                'Example' => $directory . '/' . $sourceDirectory . '/Example.php',
            ]);
            $packages->method('packageForClass')->willReturn($package);
        }

        return new Mutator(new SourceMetadata($packages));
    }
}
