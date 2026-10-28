<?php

declare(strict_types=1);

namespace SuperKernel\Aop\Tests\Fixture;

use RuntimeException;
use stdClass;

final class Subject
{
    public const string SOURCE_FILE = __FILE__;

    public string $sourceDirectory = __DIR__;

    public int $recorded = 0;

    public readonly bool $sameToken;

    public function __construct(public readonly int $factor = 2, public readonly object $token = new stdClass())
    {
        $this->sameToken = $token === $this->token;
    }

    final public function multiply(int $value): int
    {
        return $this->scale($value);
    }

    public static function double(int $value): int
    {
        return $value * 2;
    }

    public function record(int $value): void
    {
        $this->recorded = $value;
    }

    public function fail(): never
    {
        throw new RuntimeException('original failure');
    }

    public function failAfterNestedCall(): never
    {
        $this->multiply(3);
        throw new RuntimeException('outer failure');
    }

    /** @return array{string, string} */
    public function source(): array
    {
        return [
            __FILE__,
            __DIR__,
        ];
    }

    public function defaultSource(string $file = __FILE__): string
    {
        return $file;
    }

    private function scale(int $value): int
    {
        return $value * $this->factor;
    }
}
