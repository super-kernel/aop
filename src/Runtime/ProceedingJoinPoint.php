<?php

declare(strict_types=1);

namespace SuperKernel\Aop\Runtime;

use Closure;
use ReflectionClass;
use ReflectionMethod;
use SuperKernel\Aop\Contract\ProceedingJoinPointInterface;

use function is_string;
use function strrpos;
use function substr;

final readonly class ProceedingJoinPoint implements ProceedingJoinPointInterface
{
    /**
     * @param object|class-string      $instance
     * @param array<int|string, mixed> $arguments
     * @param Closure(mixed...): mixed $originalLogic
     */
    public function __construct(
        private object|string $instance,
        private string        $methodName,
        private array         $arguments,
        private Closure       $originalLogic,
        public string         $originalFile,
        public int            $originalStartLine,
    )
    {
    }

    public function getMethodReflection(): ReflectionMethod
    {
        return new ReflectionMethod($this->getTargetClass(), $this->getShortMethodName());
    }

    public function getArguments(): array
    {
        return $this->arguments;
    }

    public function getInstance(): object|string
    {
        return $this->instance;
    }

    public function proceed(): mixed
    {
        return ($this->originalLogic)(...$this->arguments);
    }

    public function getClassReflection(): ReflectionClass
    {
        return new ReflectionClass($this->getTargetClass());
    }

    /** @return class-string */
    private function getTargetClass(): string
    {
        return is_string($this->instance) ? $this->instance : $this->instance::class;
    }

    public function getShortMethodName(): string
    {
        $separator = strrpos($this->methodName, '::');

        return false === $separator ? $this->methodName : substr($this->methodName, $separator + 2);
    }
}
