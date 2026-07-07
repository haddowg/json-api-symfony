<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Attribute;

use haddowg\JsonApi\OpenApi\Metadata\Accepted;
use haddowg\JsonApi\OpenApi\Metadata\ActionResource;
use haddowg\JsonApi\OpenApi\Metadata\MetaResult;
use haddowg\JsonApi\OpenApi\Metadata\SeeOther;
use haddowg\JsonApiBundle\Action\ActionScope;
use haddowg\JsonApiBundle\Attribute\AsJsonApiAction;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for the {@see AsJsonApiAction} `asLink` flag (bundle ADR 0091) and the
 * `responds` success-response set (core ADR 0127): `asLink` is a plain flag that
 * defaults off and is accepted on a resource-scope action, but a `Collection`-scope
 * action with `asLink: true` is a constructor `\LogicException`. `responds` normalises a
 * single response to a list, defaults to the empty set (the compiler pass then defaults
 * it to a mount-type resource document), and rejects an invalid set at declaration time.
 */
#[Group('spec:actions')]
final class AsJsonApiActionTest extends TestCase
{
    #[Test]
    public function asLinkDefaultsToFalse(): void
    {
        self::assertFalse((new AsJsonApiAction(type: 'widgets', path: 'publish'))->asLink);
    }

    #[Test]
    public function asLinkIsAcceptedOnAResourceScopeAction(): void
    {
        $attribute = new AsJsonApiAction(type: 'widgets', path: 'publish', scope: ActionScope::Resource, asLink: true);

        self::assertTrue($attribute->asLink);
        self::assertSame(ActionScope::Resource, $attribute->scope);
    }

    #[Test]
    public function asLinkOnACollectionScopeActionIsRejected(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('asLink');

        new AsJsonApiAction(type: 'widgets', path: 'import', scope: ActionScope::Collection, asLink: true);
    }

    #[Test]
    public function respondsDefaultsToTheEmptySet(): void
    {
        self::assertSame([], (new AsJsonApiAction(type: 'widgets', path: 'publish'))->responds);
    }

    #[Test]
    public function respondsNormalisesASingleResponseToAList(): void
    {
        $attribute = new AsJsonApiAction(type: 'jobs', path: 'poll', responds: new SeeOther());

        self::assertEquals([new SeeOther()], $attribute->responds);
    }

    #[Test]
    public function respondsAcceptsAList(): void
    {
        $attribute = new AsJsonApiAction(type: 'jobs', path: 'poll', responds: [new Accepted('jobs'), new SeeOther()]);

        self::assertCount(2, $attribute->responds);
    }

    #[Test]
    public function anInvalidRespondsSetIsRejected(): void
    {
        // Two 200-status responses (a resource document and a meta document) collide.
        $this->expectException(\InvalidArgumentException::class);

        new AsJsonApiAction(type: 'widgets', path: 'publish', responds: [new ActionResource('widgets'), new MetaResult()]);
    }
}
