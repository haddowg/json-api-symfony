<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The Phase-3 S2 acceptance suite: the read related and relationship endpoints,
 * plus the compound (`?include`) document, on both providers.
 *
 *  - `GET /{type}/{id}/{relationship}` — the *related* resource(s): a single full
 *    resource for a to-one (`data:null` when empty), a list of full resources for
 *    a to-many, each through the related type's serializer.
 *  - `GET /{type}/{id}/relationships/{relationship}` — the relationship *linkage*:
 *    `type`/`id` identifiers only, through the parent serializer.
 *  - `?include=…` — the related resources appear in the compound document's
 *    top-level `included` member on a single-resource and a collection fetch.
 *
 * Abstract over the kernel so the **same assertions** run against the in-memory
 * provider ({@see InMemoryRelationshipEndpointTest}) and the Doctrine provider
 * ({@see DoctrineRelationshipEndpointTest}); both serve the shared
 * `BaseArticleResource` relationship declaration over the shared
 * {@see \haddowg\JsonApiBundle\Tests\Functional\App\ArticleFixtures} seeds, so a
 * failure on one provider localizes the bug to that provider's execution.
 *
 * Advanced query parameters on a related collection (filter / sort / page on the
 * related type) are out of scope for this slice and not asserted here.
 */
abstract class RelationshipEndpointConformanceTestCase extends JsonApiFunctionalTestCase
{
    #[Test]
    #[Group('spec:fetching-relationships')]
    public function aRelatedToOneEndpointRendersASingleFullResource(): void
    {
        // Article 1 is authored by a1 (Ada Lovelace) — the related endpoint emits
        // the full authors resource, not just linkage.
        $document = $this->fetchDocument('/articles/1/author');

        $data = $document['data'] ?? null;
        self::assertIsArray($data);
        self::assertSame('authors', $data['type'] ?? null);
        self::assertSame('a1', $data['id'] ?? null);

        $attributes = $data['attributes'] ?? null;
        self::assertIsArray($attributes);
        self::assertSame('Ada Lovelace', $attributes['name'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function aRelatedToManyEndpointRendersAListOfFullResources(): void
    {
        // Article 1 owns comments c1, c2 in declaration order.
        $document = $this->fetchDocument('/articles/1/comments');

        $data = $document['data'] ?? null;
        self::assertIsArray($data);
        self::assertCount(2, $data);

        self::assertSame(
            [
                ['type' => 'comments', 'id' => 'c1', 'body' => 'First!'],
                ['type' => 'comments', 'id' => 'c2', 'body' => 'Nice write-up.'],
            ],
            $this->fullComments($data),
        );
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function aRelatedToOneEndpointRendersDataNullForAnEmptyToOne(): void
    {
        // Article 5 is deliberately authorless (ArticleFixtures::relationships()):
        // the related endpoint must render 200 with data:null, not a 404.
        $document = $this->fetchDocument('/articles/5/author');

        self::assertArrayHasKey('data', $document);
        self::assertNull($document['data']);
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function aRelatedToManyEndpointRendersAnEmptyListForAnEmptyToMany(): void
    {
        // Article 4 has an author but no comments.
        $document = $this->fetchDocument('/articles/4/comments');

        self::assertSame([], $document['data'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function aRelationshipToOneEndpointRendersASingleIdentifier(): void
    {
        $document = $this->fetchDocument('/articles/1/relationships/author');

        self::assertSame(['type' => 'authors', 'id' => 'a1'], $document['data'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function aRelationshipToManyEndpointRendersAListOfIdentifiers(): void
    {
        $document = $this->fetchDocument('/articles/1/relationships/comments');

        self::assertSame(
            [
                ['type' => 'comments', 'id' => 'c1'],
                ['type' => 'comments', 'id' => 'c2'],
            ],
            $this->identifiers($document['data'] ?? null),
        );
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function aRelationshipToOneEndpointRendersNullDataForAnEmptyToOne(): void
    {
        // The linkage endpoint over the authorless article 5 must render 200 with
        // data:null (no identifier), not a 404.
        $document = $this->fetchDocument('/articles/5/relationships/author');

        self::assertArrayHasKey('data', $document);
        self::assertNull($document['data']);
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function aMissingParentOnARelatedEndpointRendersA404(): void
    {
        $this->assertNotFound('/articles/999/author');
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function aMissingParentOnARelationshipEndpointRendersA404(): void
    {
        $this->assertNotFound('/articles/999/relationships/author');
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function anUnknownRelationshipOnARelatedEndpointRendersA404(): void
    {
        $this->assertNotFound('/articles/1/bogusrel');
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function anUnknownRelationshipOnARelationshipEndpointRendersA404(): void
    {
        $this->assertNotFound('/articles/1/relationships/bogusrel');
    }

    #[Test]
    #[Group('spec:fetching-includes')]
    public function aSingleResourceFetchWithIncludeRendersACompoundDocument(): void
    {
        // ?include=author,comments must populate the top-level included member with
        // the full author and comment resources for article 1.
        $document = $this->fetchDocument('/articles/1?include=author,comments');

        $included = $document['included'] ?? null;
        self::assertIsArray($included);

        $index = $this->includedIndex($included);

        self::assertArrayHasKey('authors:a1', $index);
        self::assertSame('Ada Lovelace', $this->attribute($index, 'authors:a1', 'name'));

        self::assertArrayHasKey('comments:c1', $index);
        self::assertArrayHasKey('comments:c2', $index);
        self::assertSame('First!', $this->attribute($index, 'comments:c1', 'body'));
    }

    #[Test]
    #[Group('spec:fetching-includes')]
    public function aCollectionFetchWithIncludeRendersACompoundDocument(): void
    {
        // ?include=author on the collection must surface the distinct authors
        // (a1, a2) once each in the top-level included member.
        $document = $this->fetchDocument('/articles?include=author');

        $included = $document['included'] ?? null;
        self::assertIsArray($included);

        $index = $this->includedIndex($included);

        self::assertArrayHasKey('authors:a1', $index);
        self::assertArrayHasKey('authors:a2', $index);
        self::assertSame('Ada Lovelace', $this->attribute($index, 'authors:a1', 'name'));
        self::assertSame('Grace Hopper', $this->attribute($index, 'authors:a2', 'name'));
    }

    #[Test]
    #[Group('spec:fetching-includes')]
    public function aRelatedEndpointHonoursIncludeOnTheRelatedResource(): void
    {
        // A related endpoint is include-aware too: GET /articles/1/author?include=…
        // renders the author as primary data; with no relationships on authors the
        // included member is absent — assert the primary data still resolves so the
        // include-aware render path is exercised end to end.
        $document = $this->fetchDocument('/articles/1/author?include=');

        $data = $document['data'] ?? null;
        self::assertIsArray($data);
        self::assertSame('authors', $data['type'] ?? null);
        self::assertSame('a1', $data['id'] ?? null);
    }

    // --- helpers ---------------------------------------------------------------

    /**
     * Fetches `$path` and returns the decoded document, asserting a 200 JSON:API
     * response.
     *
     * @return array<string, mixed>
     */
    protected function fetchDocument(string $path): array
    {
        $response = $this->handle($path);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));

        return $this->decode($response);
    }

    /**
     * Asserts `$path` renders a JSON:API 404 error document.
     */
    private function assertNotFound(string $path): void
    {
        $response = $this->handle($path);

        self::assertSame(404, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));

        $errors = $this->decode($response)['errors'] ?? null;
        self::assertIsArray($errors);
        self::assertNotEmpty($errors);

        $firstError = $errors[0] ?? null;
        self::assertIsArray($firstError);
        self::assertSame('404', $firstError['status'] ?? null);
    }

    /**
     * Reduces a list of full comment resources to `{type, id, body}` triples in
     * document order.
     *
     * @return list<array{type: mixed, id: mixed, body: mixed}>
     */
    private function fullComments(mixed $data): array
    {
        self::assertIsArray($data);

        $comments = [];
        foreach ($data as $resource) {
            self::assertIsArray($resource);
            $attributes = $resource['attributes'] ?? null;
            self::assertIsArray($attributes);
            $comments[] = [
                'type' => $resource['type'] ?? null,
                'id' => $resource['id'] ?? null,
                'body' => $attributes['body'] ?? null,
            ];
        }

        return $comments;
    }

    /**
     * Reduces a to-many `data` payload to a list of `{type, id}` identifiers in
     * document order.
     *
     * @return list<array{type: mixed, id: mixed}>
     */
    private function identifiers(mixed $data): array
    {
        self::assertIsArray($data);

        $identifiers = [];
        foreach ($data as $identifier) {
            self::assertIsArray($identifier);
            $identifiers[] = ['type' => $identifier['type'] ?? null, 'id' => $identifier['id'] ?? null];
        }

        return $identifiers;
    }

    /**
     * Indexes a compound document's `included` member by `"{type}:{id}"`, so
     * membership and attributes are asserted independent of array order.
     *
     * @param array<mixed> $included
     *
     * @return array<string, array<string, mixed>>
     */
    private function includedIndex(array $included): array
    {
        $index = [];
        foreach ($included as $resource) {
            self::assertIsArray($resource);
            $type = $resource['type'] ?? null;
            $id = $resource['id'] ?? null;
            self::assertIsString($type);
            self::assertIsString($id);
            /** @var array<string, mixed> $resource */
            $index[$type . ':' . $id] = $resource;
        }

        return $index;
    }

    /**
     * The named attribute of the indexed resource keyed `"{type}:{id}"`.
     *
     * @param array<string, array<string, mixed>> $index
     */
    private function attribute(array $index, string $key, string $name): mixed
    {
        $attributes = $index[$key]['attributes'] ?? null;
        self::assertIsArray($attributes);

        return $attributes[$name] ?? null;
    }
}
