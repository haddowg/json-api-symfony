<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Attribute;

use haddowg\JsonApiBundle\Action\ActionScope;
use haddowg\JsonApiBundle\Attribute\AsJsonApiAction;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for the {@see AsJsonApiAction} `asLink` flag (bundle ADR 0091): it is
 * a plain flag that defaults off and is accepted on a resource-scope action, but a
 * `Collection`-scope action with `asLink: true` is a constructor `\LogicException` —
 * a collection action has no resource to hang a link on, so an incoherent declaration
 * never compiles.
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
}
