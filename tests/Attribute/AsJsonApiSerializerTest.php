<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Attribute;

use haddowg\JsonApi\OpenApi\Metadata\OperationExample;
use haddowg\JsonApiBundle\Attribute\AsJsonApiSerializer;
use haddowg\JsonApiBundle\Operation\Operation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The vendor extensions a standalone serializer declares, validated like the resource
 * attribute's, with "exposed" meaning the serializer's own `operations` list.
 */
final class AsJsonApiSerializerTest extends TestCase
{
    #[Test]
    public function vendorExtensionsAreStoredAsDeclared(): void
    {
        $attribute = new AsJsonApiSerializer(
            type: 'shelves',
            operations: [Operation::FetchOne],
            extensions: ['x-feature' => 'shelving'],
            operationExtensions: [Operation::FetchOne->name => ['x-cache' => 'long']],
        );

        self::assertSame(['x-feature' => 'shelving'], $attribute->extensions);
        self::assertSame(['FetchOne' => ['x-cache' => 'long']], $attribute->operationExtensions);
    }

    #[Test]
    public function aSerializeOnlyTypeMayDeclareTypeWideExtensions(): void
    {
        $attribute = new AsJsonApiSerializer(type: 'shelves', extensions: ['x-feature' => 'shelving']);

        self::assertSame(['x-feature' => 'shelving'], $attribute->extensions);
        self::assertSame([], $attribute->operationExtensions);
    }

    /**
     * @return iterable<string, array{list<Operation>, array<string, mixed>, array<string, array<string, mixed>>, string}>
     */
    public static function invalidExtensions(): iterable
    {
        yield 'type-wide name without x-' => [[], ['feature' => 'shelving'], [], 'must start with "x-"'];
        yield 'type-wide bare x-' => [[], ['x-' => true], [], 'must start with "x-"'];
        yield 'type-wide reserved x-oai-' => [[], ['x-oai-thing' => true], [], 'reserved'];
        yield 'type-wide reserved x-oas-' => [[], ['x-oas-thing' => true], [], 'reserved'];
        yield 'operation name without x-' => [[Operation::FetchOne], [], [Operation::FetchOne->name => ['cache' => 'long']], 'operationExtensions["FetchOne"]'];
        yield 'unknown operation key' => [[Operation::FetchOne], [], ['Publish' => ['x-feature' => 'shelving']], 'unknown operation key "Publish"'];
        yield 'operation not in the allow-list' => [[Operation::FetchOne], [], [Operation::Update->name => ['x-feature' => 'shelving']], 'does not expose'];
        yield 'any operation on a serialize-only type' => [[], [], [Operation::FetchOne->name => ['x-feature' => 'shelving']], 'it exposes none'];
    }

    /**
     * @param list<Operation>                     $operations
     * @param array<string, mixed>                $extensions
     * @param array<string, array<string, mixed>> $operationExtensions
     */
    #[Test]
    #[DataProvider('invalidExtensions')]
    public function anInvalidVendorExtensionDeclarationIsRejected(array $operations, array $extensions, array $operationExtensions, string $message): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage($message);

        new AsJsonApiSerializer(type: 'shelves', operations: $operations, extensions: $extensions, operationExtensions: $operationExtensions);
    }

    #[Test]
    public function operationExamplesMayOnlyNameAnExposedOperation(): void
    {
        $attribute = new AsJsonApiSerializer(type: 'shelves', operations: [Operation::FetchOne], operationExamples: ['fetchOne' => new OperationExample()]);
        self::assertArrayHasKey('fetchOne', $attribute->operationExamples);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('does not expose');

        new AsJsonApiSerializer(type: 'shelves', operations: [Operation::FetchOne], operationExamples: ['fetchCollection' => false]);
    }
}
