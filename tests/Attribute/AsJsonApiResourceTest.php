<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Attribute;

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
}
