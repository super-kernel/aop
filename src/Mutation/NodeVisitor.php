<?php

declare(strict_types=1);

namespace SuperKernel\Aop\Mutation;

use LogicException;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\NodeVisitorAbstract;
use SuperKernel\Aop\Runtime\Dispatcher;
use SuperKernel\Aop\Runtime\ProceedingJoinPoint;
use SuperKernel\Aop\Runtime\SourceMetadata;
use SuperKernel\Aop\Runtime\Tracker;

use function in_array;

final class NodeVisitor extends NodeVisitorAbstract
{
    public function __construct(private readonly ?string $sourcePath = null)
    {
    }

    public function leaveNode(Node $node): ?Node
    {
        if (null !== $this->sourcePath) {
            if ($node instanceof Scalar\MagicConst\File) {
                return new Expr\ConstFetch(new FullyQualified(SourceMetadata::fileConstant($this->sourcePath)));
            }

            if ($node instanceof Scalar\MagicConst\Dir) {
                return new Expr\ConstFetch(new FullyQualified(SourceMetadata::directoryConstant($this->sourcePath)));
            }
        }

        if (!$node instanceof Stmt\ClassMethod || null === $node->stmts) {
            return null;
        }

        $this->assertSupported($node);
        $statements = [];

        foreach ($node->stmts as $statement) {
            $statements[] = new Stmt\Expression(new Expr\StaticCall(new FullyQualified(Tracker::class), 'setLine', [
                new Arg(new Scalar\Int_($statement->getStartLine())),
            ]));
            $statements[] = $statement;
        }

        $uses = [];

        foreach ($node->params as $param) {
            $uses[] = new Node\ClosureUse(clone $param->var, true);
        }

        // Capture the original parameters so default objects are not constructed twice.
        $closure = new Expr\Closure();
        $closure->uses = $uses;
        $closure->static = $node->isStatic();
        $closure->stmts = $statements;
        $point = new Expr\New_(new FullyQualified(ProceedingJoinPoint::class), [
            new Arg($node->isStatic()
                ? new Expr\ClassConstFetch(new Name('static'), 'class')
                : new Expr\Variable('this')),
            new Arg(new Scalar\String_($node->name->toString())),
            new Arg(new Expr\FuncCall(new FullyQualified('func_get_args'))),
            new Arg($closure),
            new Arg(null === $this->sourcePath
                ? new Scalar\MagicConst\File()
                : new Expr\ConstFetch(new FullyQualified(SourceMetadata::fileConstant($this->sourcePath)))),
            new Arg(new Scalar\Int_($node->getStartLine())),
        ]);
        $dispatch = new Expr\StaticCall(new FullyQualified(Dispatcher::class), 'dispatch', [
            new Arg($point),
        ]);
        $returnType = $node->returnType instanceof Node\Identifier ? $node->returnType->toString() : null;
        $withoutReturn = in_array($node->name->toLowerString(), [
            '__construct',
            '__destruct',
        ], true) || in_array($returnType, [
            'void',
            'never',
        ], true);
        $node->stmts = [
            $withoutReturn ? new Stmt\Expression($dispatch) : new Stmt\Return_($dispatch),
        ];

        return $node;
    }

    private function assertSupported(Stmt\ClassMethod $method): void
    {
        if ($method->byRef) {
            throw new LogicException('AOP does not support methods returning by reference.');
        }

        foreach ($method->params as $param) {
            if ($param->byRef || $param->variadic || [] !== $param->hooks) {
                throw new LogicException('AOP does not support reference, variadic, or hooked promoted parameters.');
            }
        }

        $unsupported = new NodeFinder()->findFirst($method->stmts ?? [], static fn(
            Node $node,
        ): bool => $node instanceof Expr\Yield_ || $node instanceof Expr\YieldFrom || $node instanceof Stmt\Static_
            || $node instanceof Scalar\MagicConst\Function_ || $node instanceof Scalar\MagicConst\Method
            || $node instanceof Scalar\MagicConst\Line);

        if (null !== $unsupported) {
            throw new LogicException(
                'AOP cannot preserve generators, static locals, or method/line magic constants.',
            );
        }
    }
}
