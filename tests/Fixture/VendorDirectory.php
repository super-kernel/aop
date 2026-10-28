<?php

declare(strict_types=1);

namespace SuperKernel\Aop\Tests\Fixture;

use RuntimeException;
use SuperKernel\Contract\VendorDirInterface;

use function chmod;
use function is_dir;
use function mkdir;
use function rmdir;
use function scandir;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

final readonly class VendorDirectory implements VendorDirInterface
{
    /** @var non-empty-string */
    public string $path;

    /** @throws RuntimeException When the temporary vendor directory cannot be created */
    public function __construct()
    {
        $path = tempnam(sys_get_temp_dir(), 'aop-test-');

        if (false === $path || !unlink($path) || !mkdir($path, 0o700)) {
            throw new RuntimeException('Unable to create a temporary vendor directory.');
        }

        $this->path = $path;
    }

    public function __toString(): string
    {
        return $this->path;
    }

    public function remove(): void
    {
        $this->removeDirectory($this->path);
    }

    private function removeDirectory(string $path): void
    {
        chmod($path, 0o700);

        foreach (scandir($path) ?: [] as $name) {
            if ('.' === $name || '..' === $name) {
                continue;
            }

            $entry = $path . '/' . $name;

            if (is_dir($entry)) {
                $this->removeDirectory($entry);
            } else {
                unlink($entry);
            }
        }

        rmdir($path);
    }
}
