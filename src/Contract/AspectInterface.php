<?php

declare(strict_types=1);

namespace SuperKernel\Aop\Contract;

use Throwable;

/** Provides around advice for the class and method targets declared by an Aspect attribute. */
interface AspectInterface
{
    /**
     * Calls proceed() to execute the original method and returns its result, or supplies a replacement result.
     *
     * @throws Throwable When the original method or the aspect fails
     */
    public function process(ProceedingJoinPointInterface $point): mixed;
}
