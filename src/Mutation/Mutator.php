<?php

declare(strict_types=1);

namespace SuperKernel\Aop\Mutation;

use PhpParser\NodeTraverser;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use SuperKernel\Attribute\Provider;
use SuperKernel\Mimic\Contract\MutatorInterface;

#[Provider(MutatorInterface::class)]
final readonly class Mutator implements MutatorInterface
{
    private Parser $parser;

    private Standard $printer;

    public function __construct()
    {
        $this->parser = new ParserFactory()->createForHostVersion();
        $this->printer = new Standard();
    }

    public function getCacheKey(): string
    {
        return 'super-kernel/aop-v2';
    }

    public function mutate(string $code, ?string $sourcePath = null): string
    {
        $ast = $this->parser->parse($code);

        if (null === $ast) {
            return $code;
        }

        $traverser = new NodeTraverser(new NodeVisitor($sourcePath));

        return $this->printer->prettyPrintFile($traverser->traverse($ast));
    }
}
