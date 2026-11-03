<?php

declare(strict_types=1);

namespace SuperKernel\Aop\Tests\Integration;

use PharData;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use SuperKernel\Aop\Mutation\Mutator;
use SuperKernel\Aop\Runtime\SourceMetadata;
use SuperKernel\Aop\Tests\Fixture\VendorDirectory;
use SuperKernel\Contract\PackageManagerInterface;
use SuperKernel\Contract\PackageMetadataInterface;
use Throwable;

use function constant;
use function file_put_contents;
use function mkdir;
use function realpath;
use function rename;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class SourceRelocationTest extends TestCase
{
    private const string CLASS_NAME = 'Acme\\Relocation\\Probe';

    private const string SOURCE = '<?php namespace Acme\\Relocation; class Probe { public const string FILE = __FILE__; '
        . 'public function directory(): string { return __DIR__; } }';

    private VendorDirectory $vendor;

    private string $base;

    private string $packageDirectory;

    private SourceMetadata $sources;

    protected function setUp(): void
    {
        $this->vendor = new VendorDirectory();
        $this->base = (string) realpath($this->vendor->path);
        $package = $this->createStub(PackageMetadataInterface::class);
        $package->method('name')->willReturn('acme/relocation');
        $package->method('locatedPath')->willReturnCallback(fn(): string => $this->packageDirectory);
        $packages = $this->createStub(PackageManagerInterface::class);
        $packages->method('allClasses')->willReturnCallback(fn(): array => [
            self::CLASS_NAME => $this->packageDirectory . '/Probe.php',
        ]);
        $packages->method('packageForClass')->willReturn($package);
        $this->sources = new SourceMetadata($packages);
    }

    protected function tearDown(): void
    {
        $this->vendor->remove();
    }

    /** @throws Throwable When the source cannot be woven, bound, or loaded */
    public function testCodeWovenBeforeThePackageMovesBindsTheMovedSource(): void
    {
        $code = $this->weave($this->base . '/first');
        $this->assertTrue(rename($this->base . '/first', $this->base . '/second'));
        $this->packageDirectory = $this->base . '/second';

        $this->load($code);

        $this->assertSame($this->base . '/second/Probe.php', constant(self::CLASS_NAME . '::FILE'));
        $this->assertSame($this->base . '/second', $this->directory());
    }

    /** @throws Throwable When the source cannot be woven, archived, bound, or loaded */
    public function testCodeWovenFromTheUnpackedPackageBindsItsArchivedSource(): void
    {
        $code = $this->weave($this->base . '/package');
        new PharData($this->base . '/package.tar')->buildFromDirectory($this->base . '/package');
        $this->packageDirectory = 'phar://' . $this->base . '/package.tar';

        $this->load($code);

        $this->assertSame($this->packageDirectory . '/Probe.php', constant(self::CLASS_NAME . '::FILE'));
        $this->assertSame($this->packageDirectory, $this->directory());
    }

    /** @throws Throwable When the source cannot be woven */
    private function weave(string $directory): string
    {
        mkdir($directory);
        file_put_contents($directory . '/Probe.php', self::SOURCE);
        $this->packageDirectory = $directory;

        return new Mutator($this->sources)->mutate(self::SOURCE, $directory . '/Probe.php');
    }

    /** @throws Throwable When the source constants cannot be bound or the woven code cannot be loaded */
    private function load(string $code): void
    {
        file_put_contents($this->base . '/woven.php', $code);
        $this->sources->register(self::CLASS_NAME);

        require $this->base . '/woven.php';
    }

    private function directory(): string
    {
        $class = self::CLASS_NAME;

        return new $class()->directory();
    }
}
