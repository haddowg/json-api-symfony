<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The Phase-3 foundation acceptance suite: a resource's declared relationships
 * render as JSON:API linkage on reads. `GET /articles/{id}` and a collection
 * item from `GET /articles` must each carry a `relationships` member whose
 * to-one `author` is a single resource identifier and whose to-many `comments`
 * is a list of resource identifiers, with the correct related `type` and ids.
 *
 * Abstract over the kernel so the **same assertions** run against the in-memory
 * provider ({@see InMemoryRelationshipReadTest}) and the Doctrine provider
 * ({@see DoctrineRelationshipReadTest}); both serve the shared
 * `BaseArticleResource` relationship declaration over the shared
 * {@see \haddowg\JsonApiBundle\Tests\Functional\App\ArticleFixtures} seeds, so a
 * failure on one provider localizes the bug to that provider's execution.
 *
 * Foundation only: this asserts linkage in the `relationships` member and the
 * convention `self`/`related` links each relationship carries, not
 * related/relationship endpoints, `include` or mutations — those are later
 * slices.
 */
abstract class RelationshipReadConformanceTestCase extends JsonApiFunctionalTestCase
{
    /**
     * The base URI both functional kernels configure under `json_api.base_uri`
     * (see {@see \haddowg\JsonApiBundle\Tests\Functional\App\JsonApiTestKernel}
     * and {@see \haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\DoctrineJsonApiTestKernel}).
     * The `ServerFactory` threads it to `Server::withBaseUri()`, and core builds
     * the relationship links against it by convention.
     */
    private const string BASE_URI = 'https://example.test';

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function aToOneRelationshipRendersASingleResourceIdentifier(): void
    {
        // Article 1 is authored by a1 (see ArticleFixtures::relationships()).
        $relationships = $this->relationshipsOf($this->fetchResource('/articles/1'));

        $author = $relationships['author'] ?? null;
        self::assertIsArray($author);
        self::assertArrayHasKey('data', $author);
        self::assertSame(['type' => 'authors', 'id' => 'a1'], $author['data']);
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function aToOneRelationshipCarriesConventionSelfAndRelatedLinks(): void
    {
        // Core builds, by convention, links.self = {baseUri}/{type}/{id}/relationships/{name}
        // and links.related = {baseUri}/{type}/{id}/{name} for every relationship
        // that does not opt out, on both providers.
        $relationships = $this->relationshipsOf($this->fetchResource('/articles/1'));

        $author = $relationships['author'] ?? null;
        self::assertIsArray($author);
        self::assertSame(
            [
                'self' => self::BASE_URI . '/articles/1/relationships/author',
                'related' => self::BASE_URI . '/articles/1/author',
            ],
            $author['links'] ?? null,
        );
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function aToManyRelationshipCarriesConventionSelfAndRelatedLinks(): void
    {
        $relationships = $this->relationshipsOf($this->fetchResource('/articles/1'));

        $comments = $relationships['comments'] ?? null;
        self::assertIsArray($comments);
        self::assertSame(
            [
                'self' => self::BASE_URI . '/articles/1/relationships/comments',
                'related' => self::BASE_URI . '/articles/1/comments',
            ],
            $comments['links'] ?? null,
        );
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function aToManyRelationshipRendersAListOfResourceIdentifiers(): void
    {
        // Article 1 owns comments c1 and c2 in declaration order.
        $relationships = $this->relationshipsOf($this->fetchResource('/articles/1'));

        $comments = $relationships['comments'] ?? null;
        self::assertIsArray($comments);
        self::assertArrayHasKey('data', $comments);

        self::assertSame(
            [
                ['type' => 'comments', 'id' => 'c1'],
                ['type' => 'comments', 'id' => 'c2'],
            ],
            $this->normaliseIdentifiers($comments['data']),
        );
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function anEmptyToManyRelationshipRendersAnEmptyList(): void
    {
        // Article 4 has an author (a2) but no comments.
        $relationships = $this->relationshipsOf($this->fetchResource('/articles/4'));

        $author = $relationships['author'] ?? null;
        self::assertIsArray($author);
        self::assertSame(['type' => 'authors', 'id' => 'a2'], $author['data']);

        $comments = $relationships['comments'] ?? null;
        self::assertIsArray($comments);
        self::assertArrayHasKey('data', $comments);
        self::assertSame([], $comments['data']);
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function aRelationshipWithoutTheLoadStatePolicyAlwaysEmitsData(): void
    {
        // Regression: the `comments` to-many does NOT opt into
        // linkageOnlyWhenLoaded(), so its `data` member is always present on both
        // providers regardless of any injected load-state predicate — the policy
        // is strictly opt-in and changes nothing for relations that do not enable
        // it.
        $relationships = $this->relationshipsOf($this->fetchResource('/articles/1'));

        $comments = $relationships['comments'] ?? null;
        self::assertIsArray($comments);
        self::assertArrayHasKey('data', $comments);
        self::assertSame(
            [
                ['type' => 'comments', 'id' => 'c1'],
                ['type' => 'comments', 'id' => 'c2'],
            ],
            $this->normaliseIdentifiers($comments['data']),
        );
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function aToOneRelationshipUnderTheLoadStatePolicyStillEmitsData(): void
    {
        // `lazyAuthor` opts into linkageOnlyWhenLoaded() but is a to-one: the
        // Doctrine predicate reports a to-one as always loaded (a lazy ManyToOne
        // proxy carries its identifier, so emitting the identifier needs no DB
        // load), and the in-memory kernel injects no predicate at all — so on
        // BOTH providers the `data` member is present and carries the author
        // identifier.
        $relationships = $this->relationshipsOf($this->fetchResource('/articles/1'));

        $lazyAuthor = $relationships['lazyAuthor'] ?? null;
        self::assertIsArray($lazyAuthor);
        self::assertArrayHasKey('data', $lazyAuthor);
        self::assertSame(['type' => 'authors', 'id' => 'a1'], $lazyAuthor['data']);
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function relationshipsRenderOnACollectionItem(): void
    {
        // The same linkage must appear on a primary-data item of a collection
        // fetch, not only on a single-resource fetch.
        $document = $this->fetchDocument('/articles?filter[id]=3');

        $data = $document['data'] ?? null;
        self::assertIsArray($data);
        self::assertCount(1, $data);

        $resource = $data[0] ?? null;
        self::assertIsArray($resource);
        self::assertSame('articles', $resource['type'] ?? null);
        self::assertSame('3', $resource['id'] ?? null);

        $relationships = $resource['relationships'] ?? null;
        self::assertIsArray($relationships);

        // Article 3 is authored by a1 and owns comments c4 and c5.
        $author = $relationships['author'] ?? null;
        self::assertIsArray($author);
        self::assertSame(['type' => 'authors', 'id' => 'a1'], $author['data']);

        $comments = $relationships['comments'] ?? null;
        self::assertIsArray($comments);
        self::assertSame(
            [
                ['type' => 'comments', 'id' => 'c4'],
                ['type' => 'comments', 'id' => 'c5'],
            ],
            $this->normaliseIdentifiers($comments['data']),
        );
    }

    // --- relationship-existence filters ----------------------------------------

    #[Test]
    #[Group('spec:fetching-filtering')]
    #[Group('spec:fetching-relationships')]
    public function aWhereHasFilterKeepsRowsWithANonEmptyToMany(): void
    {
        // Articles 1, 2, 3 own comments; 4 and 5 have none (ArticleFixtures).
        // The request value is ignored — presence on `comments` is the predicate.
        $document = $this->fetchDocument('/articles?filter[hasComments]=1&sort=title');

        self::assertSame(['3', '1', '2'], $this->ids($document));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    #[Group('spec:fetching-relationships')]
    public function aWhereDoesntHaveFilterKeepsRowsWithAnEmptyToMany(): void
    {
        // The complement of hasComments: articles 4 and 5 lack comments.
        $document = $this->fetchDocument('/articles?filter[lacksComments]=anything&sort=title');

        self::assertSame(['5', '4'], $this->ids($document));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    #[Group('spec:fetching-relationships')]
    public function aWhereHasFilterKeepsRowsWithANonNullToOne(): void
    {
        // Articles 1-4 have an author; article 5 is authorless. A to-one
        // translates the same EXISTS predicate as a to-many.
        $document = $this->fetchDocument('/articles?filter[hasAuthor]=1&sort=title');

        self::assertSame(['3', '1', '2', '4'], $this->ids($document));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    #[Group('spec:fetching-relationships')]
    public function aWhereDoesntHaveFilterKeepsRowsWithANullToOne(): void
    {
        // Only article 5 lacks an author.
        $document = $this->fetchDocument('/articles?filter[lacksAuthor]=1');

        self::assertSame(['5'], $this->ids($document));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    #[Group('spec:fetching-relationships')]
    public function aRelationshipExistenceFilterComposesConjunctivelyWithScalarFilters(): void
    {
        // hasComments narrows to {1,2,3}; the id set then restricts to {2,3}.
        $document = $this->fetchDocument('/articles?filter[hasComments]=1&filter[id]=2,3,4&sort=title');

        self::assertSame(['3', '2'], $this->ids($document));
    }

    // --- helpers ---------------------------------------------------------------

    /**
     * The ids of the document's primary data, in document order.
     *
     * @param array<string, mixed> $document
     *
     * @return list<string>
     */
    private function ids(array $document): array
    {
        $data = $document['data'] ?? null;
        self::assertIsArray($data);

        $ids = [];
        foreach ($data as $resource) {
            self::assertIsArray($resource);
            self::assertSame('articles', $resource['type'] ?? null);

            $id = $resource['id'] ?? null;
            self::assertIsString($id);
            $ids[] = $id;
        }

        return $ids;
    }

    /**
     * Fetches `$path` and returns the decoded document, asserting a 200 JSON:API
     * response.
     *
     * @return array<string, mixed>
     */
    private function fetchDocument(string $path): array
    {
        $response = $this->handle($path);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));

        return $this->decode($response);
    }

    /**
     * The primary-data resource object of a single-resource fetch.
     *
     * @return array<string, mixed>
     */
    private function fetchResource(string $path): array
    {
        $data = $this->fetchDocument($path)['data'] ?? null;
        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * The `relationships` member of a resource object.
     *
     * @param array<string, mixed> $resource
     *
     * @return array<string, mixed>
     */
    private function relationshipsOf(array $resource): array
    {
        $relationships = $resource['relationships'] ?? null;
        self::assertIsArray($relationships);

        /** @var array<string, mixed> $relationships */
        return $relationships;
    }

    /**
     * Reduces a to-many `data` payload to a list of `{type, id}` identifiers in
     * document order, so the assertion is independent of any extra members.
     *
     * @return list<array{type: mixed, id: mixed}>
     */
    private function normaliseIdentifiers(mixed $data): array
    {
        self::assertIsArray($data);

        $identifiers = [];
        foreach ($data as $identifier) {
            self::assertIsArray($identifier);
            $identifiers[] = ['type' => $identifier['type'] ?? null, 'id' => $identifier['id'] ?? null];
        }

        return $identifiers;
    }
}
