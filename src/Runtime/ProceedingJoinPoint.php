<?php

declare(strict_types=1);

namespace SuperKernel\Aop\Runtime;

use Closure;
use ReflectionClass;
use ReflectionMethod;
use SuperKernel\Aop\Contract\ProceedingJoinPointInterface;

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
        return new ReflectionMethod(
            JoinPointTarget::className($this->instance),
            JoinPointTarget::shortMethodName($this->methodName),
        );
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
        return new ReflectionClass(JoinPointTarget::className($this->instance));
    }

    public function getShortMethodName(): string
    {
        return JoinPointTarget::shortMethodName($this->methodName);
    }
}
