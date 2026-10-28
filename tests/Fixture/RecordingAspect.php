<?php

declare(strict_types=1);

namespace SuperKernel\Aop\Tests\Fixture;

use SuperKernel\Aop\Contract\AspectInterface;
use SuperKernel\Aop\Contract\ProceedingJoinPointInterface;

final class RecordingAspect implements AspectInterface
{
    /** @var list<string> */
    public array $events = [];

    public function process(ProceedingJoinPointInterface $point): mixed
    {
        $this->events[] = 'before ' . $point->getShortMethodName();

        try {
            return $point->proceed();
        } finally {
            $this->events[] = 'after ' . $point->getShortMethodName();
        }
    }
}
