<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Operation;

use haddowg\JsonApiBundle\Operation\TargetResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class TargetResolverTest extends TestCase
{
    #[Test]
    public function itResolvesACollectionTargetFromTheTypeAttribute(): void
    {
        $request = new Request();
        $request->attributes->set(TargetResolver::TYPE_ATTRIBUTE, 'articles');

        $target = (new TargetResolver())->resolveFromRequest($request);

        self::assertNotNull($target);
        self::assertSame('articles', $target->type);
        self::assertNull($target->id);
        self::assertFalse($target->hasId());
    }

    #[Test]
    public function itResolvesASingleTargetWhenTheIdAttributeIsPresent(): void
    {
        $request = new Request();
        $request->attributes->set(TargetResolver::TYPE_ATTRIBUTE, 'articles');
        $request->attributes->set(TargetResolver::ID_ATTRIBUTE, '42');

        $target = (new TargetResolver())->resolveFromRequest($request);

        self::assertNotNull($target);
        self::assertSame('articles', $target->type);
        self::assertSame('42', $target->id);
        self::assertTrue($target->hasId());
    }

    #[Test]
    public function itResolvesARelatedTargetFromTheRelationshipAttribute(): void
    {
        $request = new Request();
        $request->attributes->set(TargetResolver::TYPE_ATTRIBUTE, 'articles');
        $request->attributes->set(TargetResolver::ID_ATTRIBUTE, '1');
        $request->attributes->set(TargetResolver::RELATIONSHIP_ATTRIBUTE, 'author');
        $request->attributes->set(TargetResolver::RELATIONSHIP_ENDPOINT_ATTRIBUTE, false);

        $target = (new TargetResolver())->resolveFromRequest($request);

        self::assertNotNull($target);
        self::assertSame('articles', $target->type);
        self::assertSame('1', $target->id);
        self::assertSame('author', $target->relationship);
        self::assertTrue($target->hasRelationship());
        self::assertFalse($target->isRelationshipEndpoint);
    }

    #[Test]
    public function itResolvesARelationshipEndpointTargetWhenTheEndpointDefaultIsTrue(): void
    {
        $request = new Request();
        $request->attributes->set(TargetResolver::TYPE_ATTRIBUTE, 'articles');
        $request->attributes->set(TargetResolver::ID_ATTRIBUTE, '1');
        $request->attributes->set(TargetResolver::RELATIONSHIP_ATTRIBUTE, 'comments');
        $request->attributes->set(TargetResolver::RELATIONSHIP_ENDPOINT_ATTRIBUTE, true);

        $target = (new TargetResolver())->resolveFromRequest($request);

        self::assertNotNull($target);
        self::assertSame('comments', $target->relationship);
        self::assertTrue($target->isRelationshipEndpoint);
    }

    #[Test]
    public function aResourceTargetIsNeverARelationshipEndpoint(): void
    {
        // The resource route carries no {relationship} segment, so even a stray
        // endpoint default cannot flip a plain /{type}/{id} target.
        $request = new Request();
        $request->attributes->set(TargetResolver::TYPE_ATTRIBUTE, 'articles');
        $request->attributes->set(TargetResolver::ID_ATTRIBUTE, '1');
        $request->attributes->set(TargetResolver::RELATIONSHIP_ENDPOINT_ATTRIBUTE, true);

        $target = (new TargetResolver())->resolveFromRequest($request);

        self::assertNotNull($target);
        self::assertFalse($target->hasRelationship());
        self::assertFalse($target->isRelationshipEndpoint);
    }

    #[Test]
    public function itReturnsNullForANonJsonApiRoute(): void
    {
        self::assertNull((new TargetResolver())->resolveFromRequest(new Request()));
    }
}
