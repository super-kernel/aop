<?php

declare(strict_types=1);

namespace SuperKernel\Aop\Listener;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use SuperKernel;
use SuperKernel\Aop\Collector\AspectCollector;
use SuperKernel\Aop\Startup\AutoLoaderResolution;
use SuperKernel\Attribute\Listener;
use SuperKernel\Contract\ListenerInterface;
use SuperKernel\Event\BeforeApplicationStart;

use const PHP_INT_MAX;

#[Listener(PHP_INT_MAX)]
final readonly class BeforeApplicationStartListener implements ListenerInterface
{
    public function __construct(private AspectCollector $aspects, private ContainerInterface $container)
    {
    }

    public function listen(): array
    {
        return [
            SuperKernel\Event\BeforeApplicationStart::class,
        ];
    }

    /**
     * Activates target loading only when collected aspect declarations exist.
     *
     * @psalm-param BeforeApplicationStart $event
     *
     * @throws ContainerExceptionInterface When the AOP loader or its dependencies cannot be resolved
     */
    public function process(object $event): void
    {
        if ([] === $this->aspects->getClasses()) {
            return;
        }

        AutoLoaderResolution::resolve($this->container)->register();
    }
}
