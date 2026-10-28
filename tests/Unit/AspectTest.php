<?php

declare(strict_types=1);

namespace SuperKernel\Aop\Tests\Unit;

use Attribute;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SuperKernel\Aop\Attribute\Aspect;

use function class_exists;
use function spl_autoload_register;
use function spl_autoload_unregister;

final class AspectTest extends TestCase
{
    public function testNativeAttributeCanBeInstantiatedOnClasses(): void
    {
        $target = new #[Aspect] class() {
        };
        $reflection = new ReflectionClass($target);
        $aspects = $reflection->getAttributes(Aspect::class);

        $this->assertCount(1, $aspects);
        $aspect = $aspects[0]->newInstance();
        $this->assertInstanceOf(Aspect::class, $aspect);
        $this->assertSame([], $aspect->classes);
        $this->assertSame([], $aspect->methods);
        $this->assertSame([], $aspect->propertyHooks);

        $attribute = new ReflectionClass(Aspect::class);
        $this->assertTrue($attribute->isFinal());
        $this->assertTrue($attribute->isReadOnly());
        $declaration = $attribute->getAttributes(Attribute::class)[0]->newInstance();
        $this->assertSame(Attribute::TARGET_CLASS, $declaration->flags);
    }

    public function testOneNativeAttributeDeclaresMultipleClassesMethodsAndPropertyHooks(): void
    {
        $target = new #[Aspect(
            classes: [
                'Acme\Aop\FirstTarget',
                '\Acme\Aop\SecondTarget',
                ],
            methods: [
                'Acme\Aop\ThirdTarget' => [
                    'run',
                    'stop',
                    ],
                'Acme\Aop\FourthTarget' => [
                    'save',
                    ],
                ],
            propertyHooks: [
                'Acme\Aop\ThirdTarget' => [
                    'name' => [
                        'get',
                        'set',
                        ],
                    'id' => [
                        'get',
                        ],
                    ],
                'Acme\Aop\FourthTarget' => [
                    'value' => [
                        'set',
                        ],
                    ],
                ],
        )] class() {
        };
        $aspect = new ReflectionClass($target)->getAttributes(Aspect::class)[0]->newInstance();

        $this->assertSame([
            'Acme\Aop\FirstTarget',
            '\Acme\Aop\SecondTarget',
        ], $aspect->classes);
        $this->assertSame([
            'Acme\Aop\ThirdTarget' => [
                'run',
                'stop',
            ],
            'Acme\Aop\FourthTarget' => [
                'save',
            ],
        ], $aspect->methods);
        $this->assertSame([
            'Acme\Aop\ThirdTarget' => [
                'name' => [
                    'get',
                    'set',
                ],
                'id' => [
                    'get',
                ],
            ],
            'Acme\Aop\FourthTarget' => [
                'value' => [
                    'set',
                ],
            ],
        ], $aspect->propertyHooks);
    }

    public function testDeclaringPointcutsDoesNotAutoloadTheirTargets(): void
    {
        $this->assertTrue(class_exists(Aspect::class));
        $attempts = [];
        $loader = static function (string $class) use (&$attempts): void {
            $attempts[] = $class;
        };
        spl_autoload_register($loader, true, true);

        try {
            new Aspect(
                classes: [
                    'Acme\Aop\UnloadedClass',
                ],
                methods: [
                    'Acme\Aop\UnloadedMethodTarget' => [
                        'run',
                    ],
                ],
                propertyHooks: [
                    'Acme\Aop\UnloadedHookTarget' => [
                        'value' => [
                            'get',
                            'set',
                        ],
                    ],
                ],
            );
        } finally {
            spl_autoload_unregister($loader);
        }

        $this->assertSame([], $attempts);
        $this->assertFalse(class_exists('Acme\Aop\UnloadedClass', false));
        $this->assertFalse(class_exists('Acme\Aop\UnloadedMethodTarget', false));
        $this->assertFalse(class_exists('Acme\Aop\UnloadedHookTarget', false));
    }

    /** @param array<string, mixed> $arguments */
    #[DataProvider('invalidPointcuts')]
    public function testRejectsMalformedPointcuts(array $arguments): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Aspect(...$arguments);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidPointcuts(): iterable
    {
        yield 'class list keys' => [
            [
                'classes' => [
                    'target' => 'Acme\Target',
                ],
            ],
        ];

        foreach ([
            'empty'       => '',
            'non-string'  => 123,
            'wildcard'    => 'Acme\*',
            'empty part'  => 'Acme\\\\Target',
            'method name' => 'Acme\Target::run',
            'null byte'   => "Acme\0Target",
        ] as $case => $class) {
            yield 'class ' . $case => [
                [
                    'classes' => [
                        $class,
                    ],
                ],
            ];
        }

        foreach ([
            'invalid owner' => [
                '' => [
                    'run',
                ],
            ],
            'empty list' => [
                'Acme\Target' => [],
            ],
            'scalar list' => [
                'Acme\Target' => 'run',
            ],
            'associative list' => [
                'Acme\Target' => [
                    'method' => 'run',
                ],
            ],
            'invalid member' => [
                'Acme\Target' => [
                    'run()',
                ],
            ],
            'non-string member' => [
                'Acme\Target' => [
                    false,
                ],
            ],
        ] as $case => $methods) {
            yield 'methods ' . $case => [
                [
                    'methods' => $methods,
                ],
            ];
        }

        yield 'hooks invalid owner' => [
            [
                'propertyHooks' => [
                    '' => [
                        'value' => [
                            'get',
                        ],
                    ],
                ],
            ],
        ];

        foreach ([
            'empty properties'  => [],
            'scalar properties' => 'value',
            'invalid property'  => [
                '$value' => [
                    'get',
                ],
            ],
            'numeric property' => [
                0 => [
                    'get',
                ],
            ],
            'empty hooks' => [
                'value' => [],
            ],
            'scalar hooks' => [
                'value' => 'get',
            ],
            'associative hooks' => [
                'value' => [
                    'hook' => 'get',
                ],
            ],
            'unsupported hook' => [
                'value' => [
                    'isset',
                ],
            ],
            'non-string hook' => [
                'value' => [
                    true,
                ],
            ],
        ] as $case => $properties) {
            yield 'hooks ' . $case => [
                [
                    'propertyHooks' => [
                        'Acme\Target' => $properties,
                    ],
                ],
            ];
        }
    }
}
