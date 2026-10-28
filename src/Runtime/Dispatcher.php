<?php

declare(strict_types=1);

namespace SuperKernel\Aop\Runtime;

use Closure;
use InvalidArgumentException;
use SuperKernel\Mimic\Diagnostics\ExceptionPatcher;
use Throwable;

use function count;
use function is_array;
use function is_string;
use function str_contains;
use function strtolower;

final class Dispatcher
{
    /** @var array<string, Closure(ProceedingJoinPoint): mixed> */
    private static array $interceptors = [];

    /**
     * @param string|array{class-string, non-empty-string} $target
     * @param callable(ProceedingJoinPoint): mixed         $callback
     *
     * @throws InvalidArgumentException when the selector is empty or malformed
     */
    public static function intercept(string|array $target, callable $callback): void
    {
        if (is_array($target)) {
            if (2 !== count($target) || !isset($target[0], $target[1])
                || !is_string($target[0]) || !is_string($target[1]) || '' === $target[0] || '' === $target[1]) {
                throw new InvalidArgumentException('A method selector must contain a class name and method name.');
            }

            $target = $target[0] . '::' . $target[1];
        }

        if ('' === $target || str_contains($target, "\0")) {
            throw new InvalidArgumentException('An interceptor selector must be a non-empty name.');
        }

        self::$interceptors[strtolower($target)] = $callback(...);
    }

    /** @throws Throwable */
    public static function dispatch(ProceedingJoinPoint $point): mixed
    {
        $instance = $point->getInstance();
        $class = strtolower(is_string($instance) ? $instance : $instance::class);
        $method = $class . '::' . strtolower($point->getShortMethodName());
        $previousLine = Tracker::$currentLine;
        Tracker::setLine($point->originalStartLine);

        try {
            $callback = self::$interceptors[$method] ?? self::$interceptors[$class] ?? null;

            return null === $callback ? $point->proceed() : $callback($point);
        } catch (Throwable $exception) {
            if ($exception->getFile() === $point->getMethodReflection()->getFileName()) {
                ExceptionPatcher::patch($exception, $point->originalFile, Tracker::$currentLine);
            }

            throw $exception;
        } finally {
            Tracker::setLine($previousLine);
        }
    }
}
