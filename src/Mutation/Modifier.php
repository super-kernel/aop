<?php

declare(strict_types=1);

namespace SuperKernel\Aop\Mutation;

use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Property;
use PhpParser\NodeVisitorAbstract;

/** Optional visibility relaxation; the default mutator does not install this visitor. */
final class Modifier extends NodeVisitorAbstract
{
    public function enterNode(Node $node): ?Node
    {
        if ($node instanceof Class_) {
            $node->flags &= ~Modifiers::FINAL;
        }

        if ($node instanceof ClassMethod) {
            $node->flags &= ~Modifiers::FINAL;

            if ($node->isPrivate()) {
                $node->flags &= ~Modifiers::PRIVATE;
                $node->flags |= Modifiers::PROTECTED;
            }
        }

        if ($node instanceof Property) {
            if ($node->isPrivate()) {
                $node->flags &= ~Modifiers::PRIVATE;
                $node->flags |= Modifiers::PROTECTED;
            }
        }

        return $node;
    }
}
