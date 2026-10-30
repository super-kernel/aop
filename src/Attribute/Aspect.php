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
    private const string CLASS_NAME_PATTERN = '/\A\\\\?[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*'
        . '(?:\\\\[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*)*\z/';

    private const string MEMBER_NAME_PATTERN = '/\A[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*\z/';

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
            if (!is_string($class) || 1 !== preg_match(self::CLASS_NAME_PATTERN, $class)) {
                throw new InvalidArgumentException('Aspect targets must be valid class names.');
            }
        }

        foreach ($methods as $class => $names) {
            if (!is_string($class) || 1 !== preg_match(self::CLASS_NAME_PATTERN, $class)) {
                throw new InvalidArgumentException('Aspect targets must be valid class names.');
            }

            if (!is_array($names) || [] === $names || !array_is_list($names)) {
                throw new InvalidArgumentException('Aspect methods must be a non-empty list.');
            }

            foreach ($names as $name) {
                if (!is_string($name) || 1 !== preg_match(self::MEMBER_NAME_PATTERN, $name)) {
                    throw new InvalidArgumentException('Method targets must be valid member names.');
                }
            }
        }

        foreach ($propertyHooks as $class => $properties) {
            if (!is_string($class) || 1 !== preg_match(self::CLASS_NAME_PATTERN, $class)) {
                throw new InvalidArgumentException('Aspect targets must be valid class names.');
            }

            if (!is_array($properties) || [] === $properties) {
                throw new InvalidArgumentException('Aspect property hooks must map property names to hook lists.');
            }

            foreach ($properties as $property => $hooks) {
                if (!is_string($property) || 1 !== preg_match(self::MEMBER_NAME_PATTERN, $property)) {
                    throw new InvalidArgumentException('Property targets must be valid member names.');
                }

                if (!is_array($hooks) || [] === $hooks || !array_is_list($hooks)) {
                    throw new InvalidArgumentException('Property hooks must be a non-empty list.');
                }

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
}
