<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApi\Operation\Target;
use haddowg\JsonApiBundle\Action\ActionScope;
use haddowg\JsonApiBundle\Tests\Functional\App\Action\ActionInMemoryTestKernel;
use PHPUnit\Framework\Attributes\Test;

/**
 * `JsonApiRoute::of()` on related, relationship and custom action routes.
 */
final class JsonApiRouteOfRelationAndActionTest extends JsonApiRouteOfTestCase
{
    protected static function getKernelClass(): string
    {
        return ActionInMemoryTestKernel::class;
    }

    #[Test]
    public function aRelatedRoute(): void
    {
        $route = $this->route('GET', '/actionWidgets/7/owner');

        self::assertSame('owner', $route->relationship);
        self::assertFalse($route->isRelationshipEndpoint);
        self::assertEquals(new Target('actionWidgets', '7', 'owner'), $route->target());
    }

    #[Test]
    public function aRelationshipRoute(): void
    {
        $route = $this->route('DELETE', '/actionWidgets/7/relationships/owner');

        self::assertSame('owner', $route->relationship);
        self::assertTrue($route->isRelationshipEndpoint);
        self::assertEquals(new Target('actionWidgets', '7', 'owner', true), $route->target());
    }

    #[Test]
    public function aResourceScopedAction(): void
    {
        $route = $this->route('POST', '/actionWidgets/7/-actions/publish');

        self::assertSame('7', $route->id);
        self::assertSame('publish', $route->action);
        self::assertSame(ActionScope::Resource, $route->actionScope);
        self::assertFalse($route->isAtomic);
    }

    #[Test]
    public function aCollectionScopedAction(): void
    {
        $route = $this->route('PATCH', '/actionWidgets/-actions/recalculate');

        self::assertNull($route->id);
        self::assertSame('recalculate', $route->action);
        self::assertSame(ActionScope::Collection, $route->actionScope);
    }
}
