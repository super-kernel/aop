<?php

declare(strict_types=1);

namespace SuperKernel\Aop\Runtime;

use function is_string;
use function strrpos;
use function substr;

/**
 * Derives the class and short method name that identify an intercepted call for reflection.
 *
 * @internal
 */
final class JoinPointTarget
{
    /**
     * @param object|class-string $instance the target object, or the called class of a static call
     *
     * @return class-string
     */
    public static function className(object|string $instance): string
    {
        return is_string($instance) ? $instance : $instance::class;
    }

    /** Returns the part after the last `::`, or the whole name when it has no class qualifier. */
    public static function shortMethodName(string $methodName): string
    {
        $separator = strrpos($methodName, '::');

        return false === $separator ? $methodName : substr($methodName, $separator + 2);
    }
}
