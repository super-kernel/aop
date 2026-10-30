<?php

declare(strict_types=1);

namespace SuperKernel\Aop\Runtime;

use LogicException;
use RuntimeException;
use SuperKernel\Contract\PackageManagerInterface;

use function constant;
use function define;
use function defined;
use function dirname;
use function realpath;

/** Binds original source locations before proxies evaluate constant expressions. */
final readonly class SourceMetadata
{
    public function __construct(private PackageManagerInterface $packages)
    {
    }

    /**
     * Resolves a source through the package graph without loading the requested class.
     *
     * @param class-string $class
     *
     * @throws RuntimeException When the class has no resolvable source in the package graph
     * @throws LogicException   When a source constant is already bound to another location
     */
    public function register(string $class): void
    {
        $sourcePath = $this->packages->allClasses()[$class] ?? null;
        $sourcePath = null === $sourcePath ? false : realpath($sourcePath);

        if (false === $sourcePath) {
            throw new RuntimeException("The package graph has no original source for $class.");
        }

        foreach ([
            SourceConstantName::file($sourcePath)      => $sourcePath,
            SourceConstantName::directory($sourcePath) => dirname($sourcePath),
        ] as $name => $value) {
            if (defined($name)) {
                if (constant($name) !== $value) {
                    throw new LogicException("The original source constant $name is already defined.");
                }

                continue;
            }

            define($name, $value);
        }
    }

    /** @return non-empty-string */
    public static function fileConstant(string $sourcePath): string
    {
        return SourceConstantName::file($sourcePath);
    }

    /** @return non-empty-string */
    public static function directoryConstant(string $sourcePath): string
    {
        return SourceConstantName::directory($sourcePath);
    }
}
