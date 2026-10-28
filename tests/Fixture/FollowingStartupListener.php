<?php

declare(strict_types=1);

namespace SuperKernel\Aop\Tests\Fixture;

use SuperKernel;
use SuperKernel\Contract\ListenerInterface;
use SuperKernel\Event\BeforeApplicationStart;

final class FollowingStartupListener implements ListenerInterface
{
    /** @var list<object> */
    public array $events = [];

    public function listen(): array
    {
        return [
            SuperKernel\Event\BeforeApplicationStart::class,
        ];
    }

    /** @psalm-param BeforeApplicationStart $event */
    public function process(object $event): void
    {
        $this->events[] = $event;
    }
}
