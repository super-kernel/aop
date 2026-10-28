<?php

declare(strict_types=1);

namespace SuperKernel\Aop;

use Closure;
use InvalidArgumentException;
use LogicException;
use PhpParser\Error;
use Psr\Container\ContainerInterface;
use RuntimeException;
use SuperKernel\Aop\Collector\AspectCollector;
use SuperKernel\Aop\Contract\AspectInterface;
use SuperKernel\Aop\Contract\ProceedingJoinPointInterface;
use SuperKernel\Aop\Runtime\Dispatcher;
use SuperKernel\Aop\Runtime\SourceMetadata;
use SuperKernel\Contract\AutoLoaderInterface;
use SuperKernel\Mimic\MimicFactory;
use Throwable;

use function array_keys;
use function class_exists;
use function interface_exists;
use function ltrim;
use function spl_autoload_register;
use function sprintf;
use function strtolower;
use function trait_exists;

/** Loads declared target classes through AOP weaving and Mimic's source drivers. */
final class AutoLoader
{
    /** @var array<string, true> */
    private array $loading = [];

    public function __construct(
        private readonly AspectCollector     $aspects,
        private readonly AutoLoaderInterface $autoLoader,
        private readonly MimicFactory        $mimic,
        private readonly ContainerInterface  $container,
        private readonly SourceMetadata      $sources,
    )
    {
    }

    /** Prepends this callback while preserving the existing SPL loaders. */
    public function register(): void
    {
        spl_autoload_register($this, true, true);
    }

    /**
     * Transforms an unloaded target using its pending path from the shared loader.
     *
     * @param class-string $class
     *
     * @throws InvalidArgumentException When the source is unreadable or a matching handler violates AspectInterface
     * @throws Error                    When the source cannot be parsed
     * @throws LogicException           When a method or a matching property hook uses unsupported semantics
     * @throws RuntimeException         When loading recurses or fails to declare the requested class
     * @throws Throwable                When handler resolution, source transformation, or execution fails
     */
    public function __invoke(string $class): void
    {
        if (!$this->aspects->isTargetClass($class) || self::isLoaded($class)) {
            return;
        }

        $sourcePath = $this->autoLoader->getClassMap()[$class] ?? null;

        if (null === $sourcePath) {
            return;
        }

        $key = strtolower($class);

        if (isset($this->loading[$key])) {
            throw new RuntimeException("Recursive instrumentation of $class.");
        }

        $this->loading[$key] = true;

        try {
            $address = $this->mimic->prepare($sourcePath);
            $this->sources->register($class);
            $interceptors = $this->interceptorsFor($class);

            require_once $address;

            if (!self::isLoaded($class)) {
                throw new RuntimeException("The mapped source did not declare $class.");
            }

            foreach ($interceptors as $selector => $callback) {
                Dispatcher::intercept($selector, $callback);
            }
        } finally {
            unset($this->loading[$key]);
        }
    }

    /**
     * Resolves only handlers whose declarations select the class being loaded.
     *
     * @param class-string $class
     *
     * @return array<non-empty-string, Closure(ProceedingJoinPointInterface): mixed>
     *
     * @throws InvalidArgumentException When a matching handler does not implement AspectInterface
     * @throws LogicException           When the target declares unsupported property hook interception
     * @throws Throwable                When a matching handler cannot be resolved
     */
    private function interceptorsFor(string $class): array
    {
        $key = strtolower(ltrim($class, '\\'));
        $interceptors = [];

        foreach ($this->aspects->getClasses() as $handlerClass => $aspect) {
            foreach (array_keys($aspect->propertyHooks) as $target) {
                if ($key === strtolower(ltrim($target, '\\'))) {
                    throw new LogicException('AOP property hook interception is not implemented.');
                }
            }

            $selectors = [];

            foreach ($aspect->classes as $target) {
                if ($key === strtolower(ltrim($target, '\\'))) {
                    $selectors[] = $class;
                }
            }

            foreach ($aspect->methods as $target => $methods) {
                if ($key !== strtolower(ltrim($target, '\\'))) {
                    continue;
                }

                foreach ($methods as $method) {
                    $selectors[] = $class . '::' . $method;
                }
            }

            if ([] === $selectors) {
                continue;
            }

            $handler = $this->container->get($handlerClass);

            if (!$handler instanceof AspectInterface) {
                throw new InvalidArgumentException(sprintf(
                    'Aspect handler %s must implement %s.',
                    $handlerClass,
                    AspectInterface::class,
                ));
            }

            foreach ($selectors as $selector) {
                $interceptors[strtolower($selector)] = $handler->process(...);
            }
        }

        return $interceptors;
    }

    private static function isLoaded(string $class): bool
    {
        return class_exists($class, false) || interface_exists($class, false) || trait_exists($class, false);
    }
}
