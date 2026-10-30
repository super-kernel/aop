<?php

declare(strict_types=1);

namespace SuperKernel\Aop\Startup;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use SuperKernel\Aop\AutoLoader;

/**
 * Resolves the shared aspect loader from the container once startup has collected aspect declarations.
 *
 * @internal
 */
final class AutoLoaderResolution
{
    /** @throws ContainerExceptionInterface When the aspect loader cannot be resolved */
    public static function resolve(ContainerInterface $container): AutoLoader
    {
        return $container->get(AutoLoader::class);
    }
}
