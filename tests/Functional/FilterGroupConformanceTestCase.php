<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * Dual-provider acceptance for the server-composed filter groups
 * ({@see \haddowg\JsonApi\Resource\Filter\WhereAll} /
 * {@see \haddowg\JsonApi\Resource\Filter\WhereAny}) and the
 * {@see \haddowg\JsonApi\Resource\Filter\Where::fixed()} wither (#24b, core ADR
 * 0129), declared on {@see \haddowg\JsonApiBundle\Tests\Functional\App\Resource\ConstrainedFilterArticleResource}
 * and asserted end-to-end over HTTP.
 *
 * Abstract over the kernel so the **same assertions** run against the in-memory
 * provider ({@see InMemoryFilterGroupTest}) and the Doctrine provider
 * ({@see DoctrineFilterGroupTest}) — a fan-out OR search, a canned AND toggle of
 * fixed children, a nested `(A AND (B OR C))`, and a standalone `->fixed()` must
 * select identically on both (in-memory predicate recursion / Doctrine
 * `andX()`/`orX()` composite). The canonical fixtures seed `articles` ids `1`-`5`:
 * titles "JSON:API in PHP", "Second article", "Building bundles", "Zebra patterns",
 * "Async pipelines"; bodies "A worked example.", "Another one.",
 * "Symfony integration.", "Stripes, mostly.", "Queues and workers."; categories
 * guide, guide, news, guide, news.
 */
abstract class FilterGroupConformanceTestCase extends JsonApiFunctionalTestCase
{
    #[Test]
    #[Group('spec:fetching-filtering')]
    public function whereAnyFansOneValueAcrossColumnsAsAMultiColumnSearch(): void
    {
        // filter[search]=<v> -> title LIKE '%v%' OR body LIKE '%v%'. "nd" matches
        // titles "Second"(2)/"bundles"(3) and the body "...and workers."(5) — an OR
        // union across BOTH columns and different rows.
        self::assertSame(['2', '3', '5'], $this->sortedIds('/articles?filter[search]=nd'));

        // Body-only match: "queues" appears only in article 5's body.
        self::assertSame(['5'], $this->sortedIds('/articles?filter[search]=queues'));

        // Title-only match: "second" appears only in article 2's title.
        self::assertSame(['2'], $this->sortedIds('/articles?filter[search]=second'));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function whereAllOfFixedChildrenIsACannedToggleThatIgnoresTheRequestValue(): void
    {
        // filter[hotNews] present -> category = 'news' AND body LIKE '%workers%'.
        // News rows are {3,5}; only article 5's body has "workers" -> {5}.
        self::assertSame(['5'], $this->sortedIds('/articles?filter[hotNews]=1'));
        // The value is ignored: any value yields the identical result.
        self::assertSame(['5'], $this->sortedIds('/articles?filter[hotNews]=whatever'));

        // Omitting the key does NOT apply the toggle (presence-triggered).
        self::assertSame(['1', '2', '3', '4', '5'], $this->sortedIds('/articles'));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function nestedGroupEvaluatesAAndBOrC(): void
    {
        // filter[scoped]=<v> -> title LIKE '%v%' AND (category = 'guide' OR body LIKE '%workers%').
        // "async" -> title {5}; article 5 is news but its body has "workers", so the OR
        // branch admits it -> {5}.
        self::assertSame(['5'], $this->sortedIds('/articles?filter[scoped]=async'));

        // "second" -> title {2}; article 2 is a guide, so the category branch admits it -> {2}.
        self::assertSame(['2'], $this->sortedIds('/articles?filter[scoped]=second'));

        // "building" -> title {3}; article 3 is news and its body lacks "workers",
        // so the inner OR excludes it -> {} (the outer AND gates it out).
        self::assertSame([], $this->sortedIds('/articles?filter[scoped]=building'));
    }

    #[Test]
    #[Group('spec:fetching-filtering')]
    public function fixedStandalonePinsTheValueRegardlessOfWhatIsSent(): void
    {
        // filter[onlyGuides]=<anything> -> category = 'guide' (rows 1,2,4).
        self::assertSame(['1', '2', '4'], $this->sortedIds('/articles?filter[onlyGuides]=1'));
        // Sending 'news' does NOT filter for news — the fixed 'guide' wins.
        self::assertSame(['1', '2', '4'], $this->sortedIds('/articles?filter[onlyGuides]=news'));

        // Omitting the key does NOT apply it (contrast ->default()).
        self::assertSame(['1', '2', '3', '4', '5'], $this->sortedIds('/articles'));
    }

    /**
     * The numerically-sorted ids of `$path`'s primary `articles` data — a stable
     * order for set-membership assertions independent of the provider's default
     * ordering.
     *
     * @return list<string>
     */
    private function sortedIds(string $path): array
    {
        $ids = $this->ids($this->fetchDocument($path));
        \sort($ids, \SORT_NUMERIC);

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
     * The ids of the document's primary `articles` data, in document order.
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
}
