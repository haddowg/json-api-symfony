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
    public function itReturnsNullForANonJsonApiRoute(): void
    {
        self::assertNull((new TargetResolver())->resolveFromRequest(new Request()));
    }
}
