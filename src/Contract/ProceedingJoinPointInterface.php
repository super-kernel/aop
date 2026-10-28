<?php

declare(strict_types=1);

namespace SuperKernel\Aop\Contract;

use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use Throwable;

/** Describes an intercepted method call and provides access to its original body. */
interface ProceedingJoinPointInterface
{
    /** @throws ReflectionException When the target method cannot be reflected */
    public function getMethodReflection(): ReflectionMethod;

    /** @return array<int|string, mixed> */
    public function getArguments(): array;

    /** @return object|class-string */
    public function getInstance(): object|string;

    /** @throws Throwable When the original method fails */
    public function proceed(): mixed;

    /**
     * @return ReflectionClass<object>
     *
     * @throws ReflectionException When the target class cannot be reflected
     */
    public function getClassReflection(): ReflectionClass;

    public function getShortMethodName(): string;
}
