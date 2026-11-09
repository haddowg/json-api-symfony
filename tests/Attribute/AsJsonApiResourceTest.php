<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Attribute;

use haddowg\JsonApi\OpenApi\Metadata\Accepted;
use haddowg\JsonApi\OpenApi\Metadata\Created;
use haddowg\JsonApi\OpenApi\Metadata\Ok;
use haddowg\JsonApi\OpenApi\Metadata\OperationExample;
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
    public function relatedOnlyAloneIsAccepted(): void
    {
        $attribute = new AsJsonApiResource(relatedOnly: true);

        self::assertTrue($attribute->relatedOnly);
        self::assertFalse((new AsJsonApiResource())->relatedOnly);
    }

    #[Test]
    public function relatedOnlyWithOperationsIsRejected(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('declares relatedOnly: true together with a non-empty operations list');

        new AsJsonApiResource(relatedOnly: true, operations: [Operation::FetchOne]);
    }

    #[Test]
    public function relatedOnlyWithReadOnlyIsRejected(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('declares relatedOnly: true together with readOnly: true');

        new AsJsonApiResource(relatedOnly: true, readOnly: true);
    }

    #[Test]
    public function aRelatedOnlyTypeExposesNoOperationToOverride(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('not exposed');

        new AsJsonApiResource(relatedOnly: true, fetchOne: new Ok());
    }

    #[Test]
    public function singularDefaultsToNullAndIsStoredAsDeclared(): void
    {
        self::assertNull((new AsJsonApiResource())->singular);
        self::assertSame('media item', (new AsJsonApiResource(singular: 'media item'))->singular);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function blankSingulars(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace' => ['   '];
    }

    #[Test]
    #[DataProvider('blankSingulars')]
    public function aBlankSingularIsRejected(string $singular): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('singular must be a non-blank noun');

        new AsJsonApiResource(singular: $singular);
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
        yield 'unknown operation name' => [[], ['publish' => ['x-feature' => 'agency']], 'unknown operation key "publish"'];
        yield 'relation operation without a relation' => [[], ['fetchRelated' => ['x-mcp' => false]], 'unknown operation key "fetchRelated"'];
        yield 'relation operation with an empty relation' => [[], ['fetchRelated.' => ['x-mcp' => false]], 'unknown operation key "fetchRelated."'];
        yield 'crud operation with a relation' => [[], ['delete.agencies' => ['x-mcp' => false]], 'unknown operation key "delete.agencies"'];
        yield 'atomic is not a type operation' => [[], ['atomic' => ['x-mcp' => false]], 'unknown operation key "atomic"'];
        yield 'named operation without x-' => [[], ['fetchRelated.agencies' => ['mcp' => false]], 'operationExtensions["fetchRelated.agencies"] declares "mcp"'];
        yield 'named operation reserved x-oai-' => [[], ['fetchRelationship.agencies' => ['x-oai-thing' => true]], 'reserved'];
        yield 'same operation named both ways' => [[], [Operation::Delete->name => ['x-rate-limit' => 10], 'delete' => ['x-feature' => 'agency']], 'names the same operation as both "Delete" and "delete"'];
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

    #[Test]
    public function operationExtensionsTakeCoreOperationNamesBesideCaseNames(): void
    {
        $attribute = new AsJsonApiResource(operationExtensions: [
            Operation::Delete->name => ['x-rate-limit' => 10],
            'fetchRelated.agencies' => ['x-mcp' => false],
            'updateRelationship.agencies' => ['x-feature' => 'agency-admin'],
            'create' => ['x-feature' => 'agency-admin'],
        ]);

        self::assertSame([
            'Delete' => ['x-rate-limit' => 10],
            'fetchRelated.agencies' => ['x-mcp' => false],
            'updateRelationship.agencies' => ['x-feature' => 'agency-admin'],
            'create' => ['x-feature' => 'agency-admin'],
        ], $attribute->operationExtensions);
    }

    #[Test]
    public function aReadOnlyTypeMayNameItsReadEndpoints(): void
    {
        $attribute = new AsJsonApiResource(readOnly: true, operationExtensions: [
            'fetchOne' => ['x-cache' => 'long'],
            'fetchRelated.agencies' => ['x-mcp' => false],
            'fetchRelationship.agencies' => ['x-mcp' => false],
        ]);

        self::assertCount(3, $attribute->operationExtensions);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function namedOperationsAReadOnlyTypeDoesNotExpose(): iterable
    {
        yield 'create' => ['create'];
        yield 'update' => ['update'];
        yield 'delete' => ['delete'];
        yield 'updateRelationship' => ['updateRelationship.agencies'];
        yield 'addRelationship' => ['addRelationship.agencies'];
        yield 'removeRelationship' => ['removeRelationship.agencies'];
    }

    #[Test]
    #[DataProvider('namedOperationsAReadOnlyTypeDoesNotExpose')]
    public function aNamedOperationTheTypeDoesNotExposeIsRejected(string $name): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(\sprintf('operationExtensions["%s"] targets', $name));

        new AsJsonApiResource(readOnly: true, operationExtensions: [$name => ['x-feature' => 'agency']]);
    }

    #[Test]
    public function aRelationshipMutationNeedsUpdateEvenWhenOtherWritesAreExposed(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('operationExtensions["addRelationship.agencies"] targets a relationship mutation');

        new AsJsonApiResource(
            operations: [Operation::FetchOne, Operation::Create],
            operationExtensions: ['addRelationship.agencies' => ['x-feature' => 'agency']],
        );
    }

    #[Test]
    public function operationExamplesAreCarriedAsDeclared(): void
    {
        $example = new OperationExample(responses: [200 => ['meta' => ['ok' => true]]]);
        $attribute = new AsJsonApiResource(operationExamples: ['fetchOne' => $example, 'fetchRelated.author' => false]);

        self::assertSame(['fetchOne' => $example, 'fetchRelated.author' => false], $attribute->operationExamples);
    }

    #[Test]
    public function anOperationExampleForAnOperationTheTypeDoesNotExposeIsRejected(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('operationExamples["create"] targets an operation this type does not expose');

        new AsJsonApiResource(readOnly: true, operationExamples: ['create' => false]);
    }

    #[Test]
    public function anOperationExampleKeyedByTheBundleCaseNameIsRejected(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('unknown operation key "Create"');

        new AsJsonApiResource(operationExamples: [Operation::Create->name => false]);
    }

    #[Test]
    public function operationScopesAreKeyedLikeOperationExamples(): void
    {
        $attribute = new AsJsonApiResource(operationScopes: ['delete' => ['articles:admin'], 'fetchRelated.author' => []]);

        self::assertSame(['delete' => ['articles:admin'], 'fetchRelated.author' => []], $attribute->operationScopes);
    }

    #[Test]
    public function aReadScopeMayBeDeclaredForATypeThatDoesNotExposeTheRead(): void
    {
        $attribute = new AsJsonApiResource(operations: [Operation::Create], operationScopes: ['fetchOne' => ['articles:read'], 'fetchCollection' => ['articles:read']]);

        self::assertSame(['articles:read'], $attribute->operationScopes['fetchOne'] ?? null);
    }

    #[Test]
    public function aWriteScopeForAnOperationTheTypeDoesNotExposeIsRejected(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('operationScopes["update"] targets an operation this type does not expose');

        new AsJsonApiResource(readOnly: true, operationScopes: ['update' => ['articles:write']]);
    }

    #[Test]
    public function anOperationScopeKeyThatIsNotAnOperationNameIsRejected(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('AsJsonApiResource operationScopes has an unknown operation key "Delete"');

        new AsJsonApiResource(operationScopes: ['Delete' => ['articles:admin']]);
    }

    #[Test]
    public function operationScopesMustBeAListOfStrings(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('AsJsonApiResource operationScopes["delete"] must be a list of scopes, got string.');

        // @phpstan-ignore argument.type
        new AsJsonApiResource(operationScopes: ['delete' => 'articles:admin']);
    }

    #[Test]
    public function deprecatedOperationsNameOperationsLikeOperationExamples(): void
    {
        $attribute = new AsJsonApiResource(deprecatedOperations: ['delete', 'fetchRelated.author', 'removeRelationship.tags']);

        self::assertSame(['delete', 'fetchRelated.author', 'removeRelationship.tags'], $attribute->deprecatedOperations);
    }

    /**
     * @return iterable<string, array{array<mixed>, bool|null, string}>
     */
    public static function invalidDeprecatedOperations(): iterable
    {
        yield 'a bundle case name' => [['Delete'], false, 'AsJsonApiResource deprecatedOperations has an unknown operation key "Delete"'];
        yield 'an unknown name' => [['archive'], false, 'AsJsonApiResource deprecatedOperations has an unknown operation key "archive"'];
        yield 'a relation kind without a relation' => [['fetchRelated'], false, 'unknown operation key "fetchRelated"'];
        yield 'a CRUD operation the type does not expose' => [['update'], true, 'AsJsonApiResource deprecatedOperations["update"] targets an operation this type does not expose'];
        yield 'a read the type does not expose' => [['fetchOne'], null, 'deprecatedOperations["fetchOne"] targets an operation this type does not expose'];
        yield 'a relationship mutation without Update' => [['addRelationship.tags'], true, 'AsJsonApiResource deprecatedOperations["addRelationship.tags"] targets a relationship mutation'];
        yield 'a map rather than a list' => [['delete' => true], false, 'AsJsonApiResource deprecatedOperations must be a list of operation names, not a map.'];
        yield 'a name that is not a string' => [[Operation::Delete], false, 'AsJsonApiResource deprecatedOperations must list operation names as strings, got haddowg\\JsonApiBundle\\Operation\\Operation.'];
    }

    /**
     * @param array<mixed> $names
     * @param bool|null    $readOnly true for a read-only type, null for a type exposing only Create
     */
    #[Test]
    #[DataProvider('invalidDeprecatedOperations')]
    public function anInvalidDeprecatedOperationIsRejected(array $names, ?bool $readOnly, string $message): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage($message);

        // @phpstan-ignore argument.type
        new AsJsonApiResource(operations: $readOnly === null ? [Operation::Create] : [], readOnly: $readOnly ?? false, deprecatedOperations: $names);
    }
}
