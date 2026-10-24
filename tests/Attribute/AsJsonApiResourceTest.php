<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Attribute;

use haddowg\JsonApi\OpenApi\Metadata\Accepted;
use haddowg\JsonApi\OpenApi\Metadata\Created;
use haddowg\JsonApi\OpenApi\Metadata\Ok;
use haddowg\JsonApi\OpenApi\Metadata\SeeOther;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Operation\Operation;
use PHPUnit\Framework\Attributes\DataProvider;
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

    #[Test]
    public function vendorExtensionsAreStoredAsDeclared(): void
    {
        $attribute = new AsJsonApiResource(
            extensions: ['x-feature' => 'agency'],
            operationExtensions: [Operation::Create->name => ['x-rate-limit' => 10]],
        );

        self::assertSame(['x-feature' => 'agency'], $attribute->extensions);
        self::assertSame(['Create' => ['x-rate-limit' => 10]], $attribute->operationExtensions);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, array<string, array<string, mixed>>, string}>
     */
    public static function invalidExtensions(): iterable
    {
        yield 'type-wide name without x-' => [['feature' => 'agency'], [], 'must start with "x-"'];
        yield 'type-wide bare x-' => [['x-' => true], [], 'must start with "x-"'];
        yield 'type-wide reserved x-oai-' => [['x-oai-thing' => true], [], 'reserved'];
        yield 'type-wide reserved x-oas-' => [['x-oas-thing' => true], [], 'reserved'];
        yield 'operation name without x-' => [[], [Operation::Update->name => ['feature' => 'agency']], 'operationExtensions["Update"]'];
        yield 'unknown operation key' => [[], ['Publish' => ['x-feature' => 'agency']], 'unknown operation key "Publish"'];
    }

    /**
     * @param array<string, mixed>                $extensions
     * @param array<string, array<string, mixed>> $operationExtensions
     */
    #[Test]
    #[DataProvider('invalidExtensions')]
    public function anInvalidVendorExtensionDeclarationIsRejected(array $extensions, array $operationExtensions, string $message): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage($message);

        new AsJsonApiResource(extensions: $extensions, operationExtensions: $operationExtensions);
    }

    #[Test]
    public function operationExtensionsForAnOperationTheTypeDoesNotExposeAreRejected(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('operationExtensions["Create"] targets an operation this type does not expose');

        new AsJsonApiResource(readOnly: true, operationExtensions: [Operation::Create->name => ['x-feature' => 'agency']]);
    }
}
