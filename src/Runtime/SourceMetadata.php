<?php

declare(strict_types=1);

namespace SuperKernel\Aop\Runtime;

use LogicException;
use RuntimeException;
use SuperKernel\Contract\PackageManagerInterface;

use function array_search;
use function constant;
use function define;
use function defined;
use function dirname;
use function is_file;
use function realpath;
use function str_starts_with;
use function strlen;
use function substr;

/** Binds original source locations before proxies evaluate constant expressions. */
final readonly class SourceMetadata
{
    public function __construct(private PackageManagerInterface $packages)
    {
    }

    /**
     * Resolves a source through the package graph without loading the requested class.
     *
     * The constants are named after the source's package-relative location and bound to its current path, so
     * generated code prepared elsewhere, such as before Phar packaging, refers to the running source.
     *
     * @param class-string $class
     *
     * @throws RuntimeException When the class has no resolvable source in the package graph
     * @throws LogicException   When a source constant is already bound to another location
     */
    public function register(string $class): void
    {
        $sourcePath = $this->packages->allClasses()[$class] ?? null;

        // realpath() cannot resolve Phar archive paths, so those are kept as given.
        if (null === $sourcePath) {
            $sourcePath = false;
        } elseif (str_starts_with($sourcePath, 'phar://')) {
            $sourcePath = is_file($sourcePath) ? $sourcePath : false;
        } else {
            $sourcePath = realpath($sourcePath);
        }

        if (false === $sourcePath) {
            throw new RuntimeException("The package graph has no original source for $class.");
        }

        $location = $this->location($sourcePath);

        foreach ([
            SourceConstantName::file($location)      => $sourcePath,
            SourceConstantName::directory($location) => dirname($sourcePath),
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

    /**
     * Identifies a source by its owning package's name and its path relative to that package's directory.
     *
     * @param non-empty-string $sourcePath A canonical source path from the package graph
     *
     * @return non-empty-string The package-relative location, or the given path outside a located package
     */
    public function location(string $sourcePath): string
    {
        $class = array_search($sourcePath, $this->packages->allClasses(), true);
        $package = false === $class ? null : $this->packages->packageForClass($class);
        $directory = $package?->locatedPath();

        if (null === $package || null === $directory || !str_starts_with($sourcePath, $directory . '/')) {
            return $sourcePath;
        }

        return $package->name() . "\0" . substr($sourcePath, strlen($directory) + 1);
    }
}
