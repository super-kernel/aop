<?php

declare(strict_types=1);

namespace SuperKernel\Aop\Tests\Fixture;

final class UnmarkedService
{
    public function value(int $value): int
    {
        return $value + 1;
    }
}
