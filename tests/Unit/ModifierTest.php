<?php

declare(strict_types=1);

namespace SuperKernel\Aop\Tests\Unit;

use PhpParser\Node\Stmt\Class_;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;
use SuperKernel\Aop\Mutation\Modifier;

final class ModifierTest extends TestCase
{
    public function testVisibilityRelaxationRemainsAnExplicitOptionalVisitor(): void
    {
        $ast = new ParserFactory()->createForHostVersion()->parse(
            '<?php final class Example { private int $value; final private function run(): void {} }',
        );
        $this->assertNotNull($ast);
        $mutated = new NodeTraverser(new Modifier())->traverse($ast);
        $class = $mutated[0];
        $this->assertInstanceOf(Class_::class, $class);
        $this->assertFalse($class->isFinal());
        $this->assertTrue($class->getProperties()[0]->isProtected());
        $method = $class->getMethod('run');
        $this->assertNotNull($method);
        $this->assertTrue($method->isProtected());
        $this->assertFalse($method->isFinal());
    }
}
