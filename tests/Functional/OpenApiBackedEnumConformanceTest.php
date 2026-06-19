<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Testing\SchemaConformanceTrait;
use haddowg\JsonApiBundle\Testing\SchemaDocumentKind;
use haddowg\JsonApiBundle\Tests\Functional\App\OpenApi\OpenApiTestKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The backed-enum leg of the conformance guarantee (design §4.8/§8): a real `products`
 * response — whose `status` attribute is a `->enum(CatalogStatus::class)` field the
 * projector hoists into the reusable `#/components/schemas/CatalogStatus` component and
 * `$ref`s from the resource schema — validates against that generated component.
 *
 * Single-provider (in-memory), because this proves a **projection-fidelity** property (the
 * enum component faithfully describes the wire value), not a data-layer one; the
 * dual-provider {@see OpenApiConformanceTestCase} covers attribute/relationship/compound
 * projection across both persisters over the `articles` surface, which carries no backed
 * enum.
 */
final class OpenApiBackedEnumConformanceTest extends JsonApiFunctionalTestCase
{
    use SchemaConformanceTrait;

    protected static function getKernelClass(): string
    {
        return OpenApiTestKernel::class;
    }

    #[Test]
    #[Group('spec:openapi')]
    public function aResourceCarryingABackedEnumAttributeValidatesAgainstItsGeneratedSchema(): void
    {
        $response = $this->handle('/products/1');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        // The wire `status` is the backing scalar of a CatalogStatus case; the generated
        // schema `$ref`s the named enum component, so a valid case validates.
        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);
        $attributes = $data['attributes'] ?? null;
        self::assertIsArray($attributes);
        self::assertContains($attributes['status'] ?? null, ['draft', 'published', 'archived']);

        $this->assertResponseMatchesGeneratedSchema($response, 'products', SchemaDocumentKind::Single);
    }

    #[Test]
    #[Group('spec:openapi')]
    public function aBackedEnumCollectionValidatesAgainstItsGeneratedSchema(): void
    {
        $response = $this->handle('/products');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $this->assertResponseMatchesGeneratedSchema($response, 'products', SchemaDocumentKind::Collection);
    }

    #[Test]
    #[Group('spec:openapi')]
    public function theToOneRelatedAndRelationshipDocumentsValidate(): void
    {
        $this->assertResponseMatchesGeneratedSchema($this->handle('/products/1/category'), 'products', SchemaDocumentKind::Related, 'category');
        $this->assertResponseMatchesGeneratedSchema($this->handle('/products/1/relationships/category'), 'products', SchemaDocumentKind::Relationship, 'category');
        $this->assertResponseMatchesGeneratedSchema($this->handle('/categories/1'), 'categories', SchemaDocumentKind::Single);
    }

    #[Test]
    #[Group('spec:openapi')]
    public function aResponseDivergingFromTheGeneratedSchemaIsDetected(): void
    {
        // The conformance guarantee has teeth: a body that violates the generated schema
        // (an out-of-vocabulary enum value) must fail validation. This guards the helper
        // itself — a vacuous validator that passed everything would be worthless.
        $response = $this->handle('/products/1');
        $body = \json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        \assert(\is_array($body));
        /** @var array{data: array{attributes: array<string, mixed>}} $body */
        $body['data']['attributes']['status'] = 'not-a-real-status';

        $matches = $this->bodyMatchesGeneratedComponent(
            \json_encode($body, \JSON_THROW_ON_ERROR),
            'ProductsDocument',
        );

        self::assertFalse($matches, 'An out-of-vocabulary enum value must NOT validate against the generated schema.');
    }
}
