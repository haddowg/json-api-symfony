<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The many-to-many related-collection acceptance suite: the related to-many
 * endpoint `GET /{type}/{id}/{relationship}` for a unidirectional ManyToMany
 * relation (`articles.editors` → `authors`) as a real queryable, paginated
 * collection on both providers.
 *
 * On Doctrine this exercises the subquery-scoped branch (the parent association
 * is owning-side with no single-valued inverse FK), so membership is scoped by
 * an `IN` subquery while filter/sort/count/window still apply against the
 * related `authors` vocabulary; in-memory reads the `editors` objects off the
 * parent and applies the shared criteria machinery. The same assertions run
 * against both ({@see InMemoryManyToManyRelatedCollectionTest},
 * {@see DoctrineManyToManyRelatedCollectionTest}), so a failure localizes to the
 * provider's execution.
 *
 * Order assertions always pass an explicit `sort` — many-to-many row order is
 * not otherwise guaranteed and both providers must assert identically.
 */
abstract class ManyToManyRelatedCollectionConformanceTestCase extends JsonApiFunctionalTestCase
{
    private const string BASE_URI = 'https://example.test';

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function aManyToManyRelatedCollectionScopesToItsParent(): void
    {
        // Article 1 has editors a1, a2; article 2 has only a1. a1 is shared by
        // both, so per-parent scoping must return it for each without bleed.
        $document = $this->fetchDocument('/articles/1/editors?sort=name');
        self::assertSame(['a1', 'a2'], $this->ids($document));

        $document = $this->fetchDocument('/articles/2/editors?sort=name');
        self::assertSame(['a1'], $this->ids($document));
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    #[Group('spec:fetching-sorting')]
    public function aManyToManyRelatedCollectionSortsByTheRelatedVocabulary(): void
    {
        // sort=-name is byte-desc on the author name: "Grace Hopper" > "Ada
        // Lovelace", so a2 precedes a1 — sorting against the authors vocabulary.
        $document = $this->fetchDocument('/articles/1/editors?sort=-name');

        self::assertSame(['a2', 'a1'], $this->ids($document));
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    #[Group('spec:fetching-filtering')]
    public function aManyToManyRelatedCollectionFiltersByTheRelatedVocabulary(): void
    {
        // filter[name] is the authors filter declared on BaseAuthorResource.
        $document = $this->fetchDocument('/articles/1/editors?filter[name]=Ada Lovelace&sort=name');

        self::assertSame(['a1'], $this->ids($document));
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    #[Group('spec:fetching-pagination')]
    public function aManyToManyRelatedCollectionPaginatesOverTheSubquery(): void
    {
        // editors carries a per-relation PagePaginator: page 1 of size 1 over the
        // name-sorted membership is exactly a1, with page meta and a link scoped
        // to the request path.
        $document = $this->fetchDocument('/articles/1/editors?sort=name&page[size]=1&page[number]=1');

        self::assertSame(['a1'], $this->ids($document));

        $meta = $document['meta'] ?? null;
        self::assertIsArray($meta);
        self::assertArrayHasKey('page', $meta);
        self::assertIsArray($meta['page']);

        $links = $document['links'] ?? null;
        self::assertIsArray($links);

        // A second page exists (two editors, size 1), so next/last are present.
        $next = $links['next'] ?? null;
        $last = $links['last'] ?? null;
        self::assertTrue($next !== null || $last !== null);

        // Page links are scoped to the related-collection URL the client hit.
        self::assertStringContainsString('/articles/1/editors', $this->href($last ?? $next));
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function anEmptyManyToManyRelatedCollectionRendersAnEmptyList(): void
    {
        // Article 4 has no editors, so the related collection is an empty list.
        $document = $this->fetchDocument('/articles/4/editors');

        $data = $document['data'] ?? null;
        self::assertIsArray($data);
        self::assertSame([], $data);
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
        $response = $this->handle(self::BASE_URI . $path);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));

        return $this->decode($response);
    }

    /**
     * The ids of the document's primary (related) data, in document order.
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
            $id = $resource['id'] ?? null;
            self::assertIsString($id);
            $ids[] = $id;
        }

        return $ids;
    }

    /**
     * A document link's href, whether it rendered as a string or a link object.
     */
    private function href(mixed $link): string
    {
        if (\is_array($link) && isset($link['href']) && \is_string($link['href'])) {
            return $link['href'];
        }

        self::assertIsString($link);

        return $link;
    }
}
