<?php

declare(strict_types=1);

namespace SuperKernel\Aop\Collector;

use SuperKernel\Aop\Attribute\Aspect;
use SuperKernel\Contract\AnnotationCollectorInterface;
use SuperKernel\Contract\AttributeMetadataInterface;

use function array_keys;
use function array_map;
use function array_merge;
use function ltrim;
use function strtolower;

final readonly class AspectCollector
{
    /** @var array<class-string, Aspect> */
    private array $classes;

    /** @var array<string, true> */
    private array $targets;

    public function __construct(AnnotationCollectorInterface $collector)
    {
        $this->classes = array_map(
            /** @param non-empty-list<AttributeMetadataInterface<Aspect>> $attributes */
            static fn(array $attributes): Aspect => $attributes[0]->attribute(),
            $collector->getClassesWithAttribute(Aspect::class),
        );
        $targets = [];

        foreach ($this->classes as $handler => $aspect) {
            $classes = array_merge(
                $aspect->classes,
                array_keys($aspect->methods),
                array_keys($aspect->propertyHooks),
            );

            // Preserve parameter-free declarations used with manually registered interceptors.
            if ([] === $classes) {
                $classes[] = $handler;
            }

            foreach ($classes as $class) {
                $targets[strtolower(ltrim($class, '\\'))] = true;
            }
        }

        $this->targets = $targets;
    }

    /**
     * Returns the collected aspect instances keyed by their declaring class.
     *
     * @return array<class-string, Aspect>
     */
    public function getClasses(): array
    {
        return $this->classes;
    }

    /**
     * Checks declared targets without loading their declarations.
     *
     * @param class-string $class
     */
    public function isTargetClass(string $class): bool
    {
        return isset($this->targets[strtolower(ltrim($class, '\\'))]);
    }
}
