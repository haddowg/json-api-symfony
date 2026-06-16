<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The Slice-1 acceptance suite for countable relations + `?withCount` (bundle ADR
 * 0052, core ADR 0057), run identically against the in-memory provider
 * ({@see InMemoryRelationCountTest}) and the Doctrine provider
 * ({@see DoctrineRelationCountTest}).
 *
 * `pagedComments` and `editors` are declared `countable()` on the shared
 * {@see App\Resource\BaseArticleResource}; `comments` deliberately is not (the
 * count-free witness exercised by {@see RelatedCollectionParamsConformanceTestCase}).
 * The article fixtures seed article 1 with two comments and two editors, article 2
 * with one of each, article 3 with two comments and one editor — so a batched
 * collection count is observably per-parent, not a single repeated value.
 *
 *  - `?withCount=pagedComments` on a single article emits `meta.total` on that
 *    relationship object;
 *  - `?withCount=pagedComments,editors` on the whole `/articles` collection emits
 *    each parent's per-relationship `meta.total` — counted in ONE grouped query per
 *    relation across the page (the Doctrine subclass adds a query-count probe);
 *  - a `?withCount` naming a non-countable relation (`comments`) or a to-one
 *    (`author`) is a `400` (core validates up front against the primary
 *    serializer's countable set);
 *  - a relationship NOT named in `?withCount` carries no `meta.total`, even though
 *    it is countable (the relationship-object total is gated by `?withCount`).
 */
abstract class RelationCountConformanceTestCase extends JsonApiFunctionalTestCase
{
    private const string BASE_URI = 'https://example.test';

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function withCountEmitsTheRelationshipObjectTotalOnASingleResource(): void
    {
        $document = $this->fetchDocument('/articles/1?withCount=pagedComments');

        self::assertSame(2, $this->relationshipTotal($document['data'] ?? null, 'pagedComments'));
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function withCountBatchesTheRelationshipObjectTotalAcrossACollection(): void
    {
        // Each parent's own count — proving the batch is per-parent, not one value.
        $document = $this->fetchDocument('/articles?withCount=pagedComments,editors');

        $data = $document['data'] ?? null;
        self::assertIsArray($data);

        // Article 1: 2 comments, 2 editors; article 2: 1 comment, 1 editor;
        // article 3: 2 comments, 1 editor (per-parent, proving the batch is not one
        // repeated value).
        $expected = ['1' => [2, 2], '2' => [1, 1], '3' => [2, 1]];
        $seen = [];
        foreach ($data as $resource) {
            self::assertIsArray($resource);
            $id = $resource['id'] ?? null;
            if (!\is_string($id) || !isset($expected[$id])) {
                continue;
            }

            [$comments, $editors] = $expected[$id];
            self::assertSame($comments, $this->relationshipTotal($resource, 'pagedComments'), \sprintf('article "%s" pagedComments total', $id));
            self::assertSame($editors, $this->relationshipTotal($resource, 'editors'), \sprintf('article "%s" editors total', $id));
            $seen[$id] = true;
        }

        \ksort($seen);
        self::assertSame(\array_keys($expected), \array_keys($seen), 'all expected articles were counted');
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function aRelationshipNotNamedInWithCountCarriesNoTotal(): void
    {
        // editors is countable() but not named, so it carries no meta.total — the
        // relationship-object total is gated by ?withCount, not by countable() alone.
        $document = $this->fetchDocument('/articles/1?withCount=pagedComments');

        $relationships = $this->relationships($document['data'] ?? null);
        $editors = $relationships['editors'] ?? null;
        self::assertIsArray($editors);
        self::assertArrayNotHasKey('meta', $editors, 'an unnamed countable relation carries no total');
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    #[Group('spec:errors')]
    public function aNonCountableRelationInWithCountIs400(): void
    {
        // `comments` is a to-many but not countable(): core rejects it up front
        // against the primary serializer's countable set (source.parameter withCount).
        $response = $this->handle(self::BASE_URI . '/articles/1?withCount=comments');

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['parameter' => 'withCount'], $this->firstError($this->decode($response))['source'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    #[Group('spec:errors')]
    public function aToOneRelationInWithCountIs400(): void
    {
        // `author` is a to-one — counting is a to-many concern, so it is never in the
        // countable set and ?withCount=author is a 400.
        $response = $this->handle(self::BASE_URI . '/articles/1?withCount=author');

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['parameter' => 'withCount'], $this->firstError($this->decode($response))['source'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    #[Group('spec:errors')]
    public function anUnknownRelationInWithCountIs400(): void
    {
        $response = $this->handle(self::BASE_URI . '/articles/1?withCount=nope');

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['parameter' => 'withCount'], $this->firstError($this->decode($response))['source'] ?? null);
    }

    // --- helpers ---------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    protected function fetchDocument(string $path): array
    {
        $response = $this->handle(self::BASE_URI . $path);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return $this->decode($response);
    }

    /**
     * The `meta.total` of a resource's named relationship object, asserting it is an
     * int (so a missing total fails loudly rather than returning null).
     */
    protected function relationshipTotal(mixed $resource, string $name): int
    {
        $relationship = $this->relationships($resource)[$name] ?? null;
        self::assertIsArray($relationship, \sprintf('relationship "%s" is present', $name));

        $meta = $relationship['meta'] ?? null;
        self::assertIsArray($meta, \sprintf('relationship "%s" carries meta', $name));

        $total = $meta['total'] ?? null;
        self::assertIsInt($total, \sprintf('relationship "%s" meta.total is an int', $name));

        return $total;
    }

    /**
     * The `relationships` member of a resource object.
     *
     * @return array<string, mixed>
     */
    private function relationships(mixed $resource): array
    {
        self::assertIsArray($resource);
        $relationships = $resource['relationships'] ?? null;
        self::assertIsArray($relationships);

        /** @var array<string, mixed> $relationships */
        return $relationships;
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private function firstError(array $document): array
    {
        $errors = $document['errors'] ?? null;
        self::assertIsArray($errors);
        self::assertNotEmpty($errors);

        $error = $errors[0];
        self::assertIsArray($error);

        return $error;
    }
}
