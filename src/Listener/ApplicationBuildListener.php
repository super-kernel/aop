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
use SuperKernel\Event\ApplicationBuild;
use Throwable;

#[Listener]
final readonly class ApplicationBuildListener implements ListenerInterface
{
    public function __construct(private AspectCollector $aspects, private ContainerInterface $container)
    {
    }

    public function listen(): array
    {
        return [
            SuperKernel\Event\ApplicationBuild::class,
        ];
    }

    /**
     * Prepares the proxies of collected aspect targets only when aspect declarations exist.
     *
     * @psalm-param ApplicationBuild $event
     *
     * @throws ContainerExceptionInterface When the AOP loader or its dependencies cannot be resolved
     * @throws Throwable                   When a target source cannot be read or transformed
     */
    public function process(object $event): void
    {
        if ([] === $this->aspects->getClasses()) {
            return;
        }

        AutoLoaderResolution::resolve($this->container)->prepare();
    }
}
