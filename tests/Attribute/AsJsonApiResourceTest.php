<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Attribute;

use haddowg\JsonApi\OpenApi\Metadata\Accepted;
use haddowg\JsonApi\OpenApi\Metadata\Created;
use haddowg\JsonApi\OpenApi\Metadata\Ok;
use haddowg\JsonApi\OpenApi\Metadata\SeeOther;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Operation\Operation;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for the {@see AsJsonApiResource} `readOnly` shorthand (E1): it is a
 * plain flag, and declaring it alongside a non-empty `operations` list is a
 * constructor `\LogicException` (they are mutually exclusive), so an ambiguous
 * declaration never compiles.
 */
final class AsJsonApiResourceTest extends TestCase
{
    #[Test]
    public function readOnlyDefaultsToFalse(): void
    {
        self::assertFalse((new AsJsonApiResource())->readOnly);
    }

    #[Test]
    public function readOnlyAloneIsAccepted(): void
    {
        $attribute = new AsJsonApiResource(readOnly: true);

        self::assertTrue($attribute->readOnly);
        self::assertSame([], $attribute->operations);
    }

    #[Test]
    public function anExplicitOperationsListAloneIsAccepted(): void
    {
        $attribute = new AsJsonApiResource(operations: [Operation::FetchOne]);

        self::assertFalse($attribute->readOnly);
        self::assertSame([Operation::FetchOne], $attribute->operations);
    }

    #[Test]
    public function declaringBothReadOnlyAndOperationsIsRejected(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('mutually exclusive');

        new AsJsonApiResource(readOnly: true, operations: [Operation::FetchOne]);
    }

    #[Test]
    public function responseOverridesDefaultToEmptyAndNormaliseASingleToAList(): void
    {
        $attribute = new AsJsonApiResource(create: new Created());

        self::assertEquals([new Created()], $attribute->create);
        self::assertSame([], $attribute->update);
        self::assertSame([], $attribute->delete);
        self::assertSame([], $attribute->fetchOne);
        self::assertSame([], $attribute->fetchCollection);
    }

    #[Test]
    public function aResponseListIsStored(): void
    {
        $attribute = new AsJsonApiResource(
            create: [new Created(), new Accepted('jobs')],
            fetchOne: [new Ok(), new SeeOther()],
        );

        self::assertCount(2, $attribute->create);
        self::assertCount(2, $attribute->fetchOne);
        self::assertSame(202, $attribute->create[1]->status());
        self::assertSame('jobs', $attribute->create[1]->jobType());
    }

    #[Test]
    public function aDuplicateStatusInAResponseSetIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new AsJsonApiResource(create: [new Created(), new Created()]);
    }

    #[Test]
    public function aResponseOverrideForASuppressedOperationIsRejected(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('not exposed');

        // readOnly suppresses create, so a create response override is a contradiction.
        new AsJsonApiResource(readOnly: true, create: new Created());
    }
}
