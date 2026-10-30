<?php

declare(strict_types=1);

namespace SuperKernel\Aop\Runtime;

use function hash;

/**
 * Derives the namespaced constant names that carry an original source file and its directory.
 *
 * @internal
 */
final class SourceConstantName
{
    /** @return non-empty-string */
    public static function file(string $sourcePath): string
    {
        return __NAMESPACE__ . '\\Source\\F' . hash('sha256', $sourcePath) . '\\FILE';
    }

    /** @return non-empty-string */
    public static function directory(string $sourcePath): string
    {
        return __NAMESPACE__ . '\\Source\\F' . hash('sha256', $sourcePath) . '\\DIRECTORY';
    }
}
