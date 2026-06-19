<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Testing\SchemaConformanceTrait;
use haddowg\JsonApiBundle\Testing\SchemaDocumentKind;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The round-trip conformance guarantee (design §8, D11/G6 — bundle ADR 0081): the
 * OpenAPI document the bundle **generates** is proven to **describe the responses the
 * bundle serves**. Each test fetches a real response and validates its body against the
 * generated component schema for that document kind, via the shipped
 * {@see SchemaConformanceTrait} (which builds the document through the same
 * {@see \haddowg\JsonApiBundle\OpenApi\DocumentFactory} the warmer / controller / CLI use,
 * and validates over `opis/json-schema`'s native 2020-12 dialect).
 *
 * Abstract over the kernel so the **identical** assertions run against the in-memory
 * provider ({@see InMemoryOpenApiConformanceTest}) and the Doctrine-sqlite provider
 * ({@see DoctrineOpenApiConformanceTest}) — so the generated doc is shown to describe the
 * actual wire output regardless of how the data layer produced it (a failure on one
 * provider localizes to that persister's serialization). Both serve the shared `articles`
 * / `authors` / `comments` surface (relationships, an enumerated `category` attribute, a
 * nullable structured `address` Map) over the canonical {@see App\ArticleFixtures} seeds.
 *
 * The **backed-enum** component path (a `->enum(Class)` attribute hoisted into a reusable
 * `#/components/schemas/<Enum>` component) is the one shape this shared surface does not
 * carry; it is the dedicated witness {@see OpenApiBackedEnumConformanceTest} (the
 * `products.status` resource), kept single-provider since it is a projection-fidelity
 * proof, not a data-layer one.
 */
abstract class OpenApiConformanceTestCase extends JsonApiFunctionalTestCase
{
    use SchemaConformanceTrait;

    #[Test]
    #[Group('spec:openapi')]
    public function aSingleResourceDocumentValidatesAgainstItsGeneratedSchema(): void
    {
        $response = $this->handle('/articles/1');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        // The `articles` resource object carries an enumerated `category`, a nullable
        // `address` Map, and the `author`/`comments` relationship members — so a single
        // pass exercises attribute, enum, nullable-object and relationship projection.
        $this->assertResponseMatchesGeneratedSchema($response, 'articles', SchemaDocumentKind::Single);
    }

    #[Test]
    #[Group('spec:openapi')]
    public function aResourceCollectionDocumentValidatesAgainstItsGeneratedSchema(): void
    {
        $response = $this->handle('/articles');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $this->assertResponseMatchesGeneratedSchema($response, 'articles', SchemaDocumentKind::Collection);
    }

    #[Test]
    #[Group('spec:openapi')]
    public function aCompoundSingleDocumentWithIncludedResourcesValidates(): void
    {
        // A compound document: the primary `articles` resource plus the to-one `author`
        // and the to-many `comments` in `included`. The generated single-document envelope
        // describes the `included` member, so the whole compound body validates.
        $response = $this->handle('/articles/1?include=author,comments');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertArrayHasKey('included', $this->decode($response));

        $this->assertResponseMatchesGeneratedSchema($response, 'articles', SchemaDocumentKind::Single);
    }

    #[Test]
    #[Group('spec:openapi')]
    public function aCompoundCollectionDocumentWithIncludedResourcesValidates(): void
    {
        $response = $this->handle('/articles?include=author');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertArrayHasKey('included', $this->decode($response));

        $this->assertResponseMatchesGeneratedSchema($response, 'articles', SchemaDocumentKind::Collection);
    }

    #[Test]
    #[Group('spec:openapi')]
    public function aToOneRelatedDocumentValidatesAgainstItsGeneratedSchema(): void
    {
        // GET /articles/{id}/author — the related resource as primary data.
        $response = $this->handle('/articles/1/author');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $this->assertResponseMatchesGeneratedSchema($response, 'articles', SchemaDocumentKind::Related, 'author');
    }

    #[Test]
    #[Group('spec:openapi')]
    public function aToOneRelationshipLinkageDocumentValidatesAgainstItsGeneratedSchema(): void
    {
        // GET /articles/{id}/relationships/author — linkage only.
        $response = $this->handle('/articles/1/relationships/author');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $this->assertResponseMatchesGeneratedSchema($response, 'articles', SchemaDocumentKind::Relationship, 'author');
    }

    #[Test]
    #[Group('spec:openapi')]
    public function aToManyRelationshipLinkageDocumentValidatesAgainstItsGeneratedSchema(): void
    {
        // GET /articles/{id}/relationships/comments — a list of linkage identifiers.
        $response = $this->handle('/articles/1/relationships/comments');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $this->assertResponseMatchesGeneratedSchema($response, 'articles', SchemaDocumentKind::Relationship, 'comments');
    }

    #[Test]
    #[Group('spec:openapi')]
    public function aRelatedTypesOwnDocumentsValidate(): void
    {
        // The related `authors` type's own single + collection documents — the second
        // type the document describes — validate too.
        $this->assertResponseMatchesGeneratedSchema($this->handle('/authors/1'), 'authors', SchemaDocumentKind::Single);
        $this->assertResponseMatchesGeneratedSchema($this->handle('/authors'), 'authors', SchemaDocumentKind::Collection);
    }
}
