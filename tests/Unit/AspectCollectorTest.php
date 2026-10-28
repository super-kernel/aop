<?php

declare(strict_types=1);

namespace SuperKernel\Aop\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperKernel;
use SuperKernel\Annotator\AnnotationCollector;
use SuperKernel\Annotator\Model\AttributeMetadata;
use SuperKernel\Aop\Attribute\Aspect;
use SuperKernel\Aop\Collector\AspectCollector;

use function class_exists;

final class AspectCollectorTest extends TestCase
{
    public function testCollectsOnlyAspectsAcrossPackagesWithoutLoadingTheirClasses(): void
    {
        $rootAspect = new Aspect(
            classes: [
                'Acme\Aop\RootTarget',
            ],
            methods: [
                'Acme\Aop\MethodTarget' => [
                    'run',
                ],
            ],
            propertyHooks: [
                'Acme\Aop\HookTarget' => [
                    'value' => [
                        'get',
                        'set',
                    ],
                ],
            ],
        );
        $dependencyAspect = new Aspect();
        $rootMetadata = new AttributeMetadata($rootAspect);
        $dependencyMetadata = new AttributeMetadata($dependencyAspect);
        $collector = new AnnotationCollector([
            new SuperKernel\Annotator\Model\PackageAnnotations('fixture/application', null, [
                'Acme\\Aop\\RootAspect' => [
                    new SuperKernel\Annotator\Model\AttributeMetadata(new SuperKernel\Attribute\Listener(0)),
                    $rootMetadata,
                ],
                'Acme\\Aop\\UnrelatedAttributeOnly' => [
                    new SuperKernel\Annotator\Model\AttributeMetadata(new SuperKernel\Attribute\Listener(0)),
                ],
            ], [], []),
            new SuperKernel\Annotator\Model\PackageAnnotations('fixture/dependency', 'reference', [
                'Acme\\Aop\\DependencyAspect' => [
                    $dependencyMetadata,
                ],
                'Acme\\Aop\\RootAspect' => [
                    $dependencyMetadata,
                ],
            ], [], []),
        ]);

        $aspects = new AspectCollector($collector);

        $this->assertSame([
            'Acme\\Aop\\RootAspect'       => $rootAspect,
            'Acme\\Aop\\DependencyAspect' => $dependencyAspect,
        ], $aspects->getClasses());
        $this->assertFalse(class_exists('Acme\\Aop\\RootAspect', false));
        $this->assertFalse(class_exists('Acme\\Aop\\DependencyAspect', false));
        $this->assertFalse(class_exists('Acme\Aop\RootTarget', false));
        $this->assertFalse(class_exists('Acme\Aop\MethodTarget', false));
        $this->assertFalse(class_exists('Acme\Aop\HookTarget', false));
        $this->assertTrue($aspects->isTargetClass('Acme\Aop\RootTarget'));
        $this->assertTrue($aspects->isTargetClass('\acme\aop\methodtarget'));
        $this->assertFalse($aspects->isTargetClass('Acme\Aop\RootAspect'));
        $this->assertTrue($aspects->isTargetClass('Acme\Aop\HookTarget'));
        $this->assertTrue($aspects->isTargetClass('Acme\Aop\DependencyAspect'));
    }

    public function testEmptyMetadataProducesNoAspects(): void
    {
        $collector = new AspectCollector(new AnnotationCollector([]));

        $this->assertSame([], $collector->getClasses());
        $this->assertFalse($collector->isTargetClass('Acme\Aop\MissingTarget'));
    }
}
