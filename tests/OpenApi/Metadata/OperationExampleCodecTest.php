<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\OpenApi\Metadata;

use haddowg\JsonApi\OpenApi\Metadata\OperationExample;
use haddowg\JsonApiBundle\OpenApi\Metadata\OperationExampleCodec;
use haddowg\JsonApiBundle\Operation\Operation;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OperationExampleCodecTest extends TestCase
{
    #[Test]
    public function aMapSurvivesTheContainerAsJson(): void
    {
        $encoded = OperationExampleCodec::encodeMap('Test', [
            'fetchOne' => new OperationExample(responses: [200 => ['data' => ['type' => 'notes', 'id' => '1', 'attributes' => ['rating' => 4.0]]]]),
            'create' => new OperationExample(request: false, responses: [201 => false]),
            'update' => false,
            'delete' => null,
        ]);
        self::assertIsString($encoded);

        $decoded = \array_map(OperationExampleCodec::toExample(...), OperationExampleCodec::decodeMap($encoded));

        self::assertEquals(
            new OperationExample(responses: [200 => ['data' => ['type' => 'notes', 'id' => '1', 'attributes' => ['rating' => 4.0]]]]),
            $decoded['fetchOne'],
        );
        self::assertEquals(new OperationExample(request: false, responses: [201 => false]), $decoded['create']);
        self::assertFalse($decoded['update']);
        // A null value means no example to core, the same as false.
        self::assertFalse($decoded['delete']);
    }

    #[Test]
    public function anEmptyMapIsNotCarried(): void
    {
        self::assertNull(OperationExampleCodec::encodeMap('Test', []));
        self::assertSame([], OperationExampleCodec::decodeMap(null));
    }

    #[Test]
    public function anActionExampleKeepsNullApartFromFalse(): void
    {
        self::assertNull(OperationExampleCodec::encodeOne('Test', null));
        self::assertNull(OperationExampleCodec::decodeOne(null));

        self::assertFalse(OperationExampleCodec::decodeOne(OperationExampleCodec::encodeOne('Test', false)));

        $example = new OperationExample(request: ['data' => ['type' => 'notes']], responses: [200 => ['meta' => ['ok' => true]]]);
        $decoded = OperationExampleCodec::decodeOne(OperationExampleCodec::encodeOne('Test', $example));
        self::assertIsArray($decoded);
        self::assertEquals($example, OperationExampleCodec::toExample($decoded));
    }

    #[Test]
    public function aDocumentThatIsNotJsonEncodableFailsTheDeclaration(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('not JSON-encodable');

        OperationExampleCodec::encodeOne('Test', new OperationExample(request: ['data' => \NAN]));
    }

    #[Test]
    public function crudAndRelationKeysAreAccepted(): void
    {
        $this->expectNotToPerformAssertions();

        OperationExampleCodec::assertValid('Test', [
            'fetchCollection' => false,
            'fetchOne' => null,
            'create' => new OperationExample(),
            'fetchRelated.author' => false,
            'fetchRelationship.author' => false,
            'updateRelationship.tags' => false,
            'addRelationship.tags' => false,
            'removeRelationship.tags' => false,
        ], Operation::cases());
    }

    #[Test]
    public function anUnknownKeyIsRejected(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('unknown operation key "FetchOne"');

        OperationExampleCodec::assertValid('Test', ['FetchOne' => false], Operation::cases());
    }

    #[Test]
    public function aRelationKeyWithoutARelationIsRejected(): void
    {
        $this->expectException(\LogicException::class);

        OperationExampleCodec::assertValid('Test', ['fetchRelated.' => false], Operation::cases());
    }

    #[Test]
    public function anOperationTheTypeDoesNotExposeIsRejected(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('operationExamples["update"] targets an operation this type does not expose');

        OperationExampleCodec::assertValid('Test', ['update' => false], [Operation::FetchCollection, Operation::FetchOne]);
    }

    #[Test]
    public function aValueOfAnotherKindIsRejected(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('must be an');

        OperationExampleCodec::assertValid('Test', ['fetchOne' => ['data' => null]], Operation::cases());
    }
}
