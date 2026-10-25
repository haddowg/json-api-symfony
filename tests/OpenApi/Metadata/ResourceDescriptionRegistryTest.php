<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\OpenApi\Metadata;

use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApiBundle\OpenApi\Metadata\ResourceDescriptionRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ResourceDescriptionRegistryTest extends TestCase
{
    #[Test]
    public function eachServerHoldsOnlyWhatItsOwnClassDeclared(): void
    {
        $registry = new ResourceDescriptionRegistry(
            ['widgets' => ['description' => 'Default widgets.', 'singular' => 'widget', 'operations' => ['FetchOne' => 'Read one.']]],
            ['widgets' => ['type' => ['x-server' => 'default']]],
            ['v5' => [
                'descriptions' => ['widgets' => ['description' => 'Named widgets.']],
                'extensions' => [],
            ]],
        );

        self::assertSame('Default widgets.', $registry->descriptionFor('widgets'));
        self::assertSame('widget', $registry->singularFor('widgets'));
        self::assertSame('Read one.', $registry->operationDescriptionFor('widgets', OperationType::FetchOne));
        self::assertSame(['x-server' => 'default'], $registry->extensionsFor('widgets'));

        self::assertSame('Named widgets.', $registry->descriptionFor('widgets', 'v5'));
        self::assertNull($registry->singularFor('widgets', 'v5'));
        self::assertNull($registry->operationDescriptionFor('widgets', OperationType::FetchOne, 'v5'));
        self::assertSame([], $registry->extensionsFor('widgets', 'v5'));
        self::assertNull($registry->descriptionFor('widgets', 'v6'));
    }
}
