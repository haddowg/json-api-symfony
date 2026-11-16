<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Http;

use haddowg\JsonApi\Operation\Target;
use haddowg\JsonApiBundle\Http\ResponseHeaderOperation;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ResponseHeaderOperationTest extends TestCase
{
    #[Test]
    public function eachTargetReadsAsItsShape(): void
    {
        self::assertSame(ResponseHeaderOperation::Collection, ResponseHeaderOperation::fromTarget(new Target('articles')));
        self::assertSame(ResponseHeaderOperation::Read, ResponseHeaderOperation::fromTarget(new Target('articles', '1')));
        self::assertSame(ResponseHeaderOperation::Related, ResponseHeaderOperation::fromTarget(new Target('articles', '1', 'author')));
        self::assertSame(ResponseHeaderOperation::Relationship, ResponseHeaderOperation::fromTarget(new Target('articles', '1', 'author', true)));
    }

    #[Test]
    public function aSingletonReadsAsOneResourceBeforeItsIdIsKnown(): void
    {
        self::assertSame(ResponseHeaderOperation::Read, ResponseHeaderOperation::fromTarget(Target::singleton('inboxes')));
        self::assertSame(ResponseHeaderOperation::Related, ResponseHeaderOperation::fromTarget(Target::singleton('inboxes', relationship: 'items')));
    }
}
