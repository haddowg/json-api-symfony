<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\OpenApi\Metadata;

use haddowg\JsonApi\OpenApi\Metadata\OperationType;
use haddowg\JsonApiBundle\OpenApi\Metadata\ResourceDescriptionRegistry;
use haddowg\JsonApiBundle\Tests\Functional\App\OperationErrors\AccountLocked;
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

    #[Test]
    public function itHoldsTheSetsKeyedByOperationNamePerServer(): void
    {
        $registry = new ResourceDescriptionRegistry(
            [],
            ['widgets' => ['named' => ['fetchRelated.parts' => ['x-mcp' => false]]]],
            ['v5' => ['extensions' => ['widgets' => ['type' => ['x-feature' => 'widgets']]]]],
        );

        self::assertSame(['fetchRelated.parts' => ['x-mcp' => false]], $registry->namedOperationExtensionsFor('widgets'));
        self::assertSame([], $registry->namedOperationExtensionsFor('widgets', 'v5'));
        self::assertSame([], $registry->namedOperationExtensionsFor('gadgets'));
    }

    #[Test]
    public function itHoldsTheScopedErrorCodesPerServer(): void
    {
        $registry = new ResourceDescriptionRegistry(
            servers: ['v5' => ['errors' => ['widgets' => ['*' => [AccountLocked::class]]]]],
            errors: ['widgets' => ['update' => [AccountLocked::class]]],
        );

        self::assertSame(['update' => [AccountLocked::class]], $registry->operationErrorsFor('widgets'));
        self::assertSame(['*' => [AccountLocked::class]], $registry->operationErrorsFor('widgets', 'v5'));
        self::assertSame([], $registry->operationErrorsFor('gadgets'));
    }
}
