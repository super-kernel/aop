<?php

declare(strict_types=1);

namespace SuperKernel\Aop\Tests\Fixture;

final class SelectedService
{
    public function value(int $value): int
    {
        return $value + 1;
    }
}
