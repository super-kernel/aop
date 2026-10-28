<?php

declare(strict_types=1);

namespace SuperKernel\Aop\Attribute;

use Attribute;
use InvalidArgumentException;

use function array_is_list;
use function in_array;
use function is_array;
use function is_string;
use function preg_match;

/** Marks an aspect handler and declares the classes, methods, and property hooks it targets. */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Aspect
{
    /**
     * Target names are validated without loading or reflecting their classes.
     *
     * @param list<class-string>                                                                  $classes
     * @param array<class-string, non-empty-list<non-empty-string>>                               $methods
     * @param array<class-string, non-empty-array<non-empty-string, non-empty-list<'get'|'set'>>> $propertyHooks
     *
     * @throws InvalidArgumentException When a target name, member list, or hook declaration is malformed
     */
    public function __construct(
        public array $classes = [],
        public array $methods = [],
        public array $propertyHooks = [],
    )
    {
        if (!array_is_list($classes)) {
            throw new InvalidArgumentException('Aspect classes must be a list of class names.');
        }

        foreach ($classes as $class) {
            self::assertClassName($class);
        }

        foreach ($methods as $class => $names) {
            self::assertClassName($class);
            self::assertNonEmptyList($names, 'Aspect methods');

            foreach ($names as $name) {
                self::assertMemberName($name, 'Method');
            }
        }

        foreach ($propertyHooks as $class => $properties) {
            self::assertClassName($class);

            if (!is_array($properties) || [] === $properties) {
                throw new InvalidArgumentException('Aspect property hooks must map property names to hook lists.');
            }

            foreach ($properties as $property => $hooks) {
                self::assertMemberName($property, 'Property');
                self::assertNonEmptyList($hooks, 'Property hooks');

                foreach ($hooks as $hook) {
                    if (!in_array($hook, [
                        'get',
                        'set',
                    ], true)) {
                        throw new InvalidArgumentException('Aspect property hooks must be get or set.');
                    }
                }
            }
        }
    }

    private static function assertClassName(mixed $class): void
    {
        $pattern = '/\A\\\\?[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*'
            . '(?:\\\\[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*)*\z/';

        if (!is_string($class) || 1 !== preg_match($pattern, $class)) {
            throw new InvalidArgumentException('Aspect targets must be valid class names.');
        }
    }

    private static function assertMemberName(mixed $name, string $kind): void
    {
        if (!is_string($name) || 1 !== preg_match('/\A[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*\z/', $name)) {
            throw new InvalidArgumentException($kind . ' targets must be valid member names.');
        }
    }

    private static function assertNonEmptyList(mixed $values, string $kind): void
    {
        if (!is_array($values) || [] === $values || !array_is_list($values)) {
            throw new InvalidArgumentException($kind . ' must be a non-empty list.');
        }
    }
}
