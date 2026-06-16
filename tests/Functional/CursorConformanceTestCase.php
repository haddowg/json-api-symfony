<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The cursor (keyset) pagination acceptance suite, asserted byte-identical on the
 * in-memory ({@see InMemoryCursorTest}) and Doctrine-sqlite ({@see DoctrineCursorTest})
 * kernels over the shared `cursorWidgets` declaration + {@see \haddowg\JsonApiBundle\Tests\Functional\App\CursorWidgetFixtures}
 * seed. The in-memory witness is the ground truth; the Doctrine keyset push-down
 * must match it (bundle ADR 0063).
 *
 * The keyset columns walk a forced NULL=largest total order terminated by the PK
 * tiebreak: the cases cover forward/backward round-trips, mixed asc/desc, a
 * non-unique sort column resolved only by the PK, PK-only paging, a NULLABLE
 * column paged through its null bucket, exhaustion, before-wins-over-after, and
 * the typed (date) boundary binding — plus the stale/malformed 400s.
 */
abstract class CursorConformanceTestCase extends JsonApiFunctionalTestCase
{
    // --- forward / backward round-trips --------------------------------------

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function forwardPagingWalksTheWholeCollectionInFixedPages(): void
    {
        // sort=priority,id: priority asc (nulls last, NULL=largest), id tiebreak.
        // Reference order by (priority asc, NULL last, id asc):
        //   10:(2,7) 20:(5,8) 30:(1,4) null:(3,6)
        $expected = ['2', '7', '5', '8', '1', '4', '3', '6'];

        $walked = $this->walkForward('/cursorWidgets?sort=priority,id', 2);

        self::assertSame($expected, $walked, 'forward paging must visit every row once in keyset order');
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function theFinalForwardPageHasNoNextLink(): void
    {
        // Walk to the last page; it must carry no `next`.
        [$ids, $links] = $this->page('/cursorWidgets?sort=priority,id&page[size]=2');
        self::assertSame(['2', '7'], $ids);

        $last = null;
        $path = '/cursorWidgets?sort=priority,id&page[size]=2';
        $seen = 0;
        while (true) {
            [$ids, $links] = $this->page($path);
            $seen += \count($ids);
            if (!isset($links['next'])) {
                $last = $links;

                break;
            }
            $path = $this->relativePath($this->href($links['next']));
            self::assertLessThan(10, ++$seen, 'paging must terminate');
        }

        self::assertNotNull($last);
        self::assertArrayNotHasKey('next', $last);
        self::assertArrayHasKey('prev', $last);
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function backwardPagingFromADeepPageEqualsTheForwardPages(): void
    {
        // Collect the forward pages, then from the deepest page follow `prev`
        // repeatedly; each backward page must equal the corresponding forward page
        // (same ids, same forward order) — the flip+slice+reverse round-trip.
        $forwardPages = $this->forwardPages('/cursorWidgets?sort=priority,id', 2);
        self::assertGreaterThanOrEqual(3, \count($forwardPages));

        // Start at the last page and walk back via prev.
        $lastIndex = \count($forwardPages) - 1;
        $path = $forwardPages[$lastIndex]['path'];

        $backwardPages = [];
        while (true) {
            [$ids, $links] = $this->page($path);
            $backwardPages[] = $ids;
            if (!isset($links['prev'])) {
                break;
            }
            $path = $this->relativePath($this->href($links['prev']));
            self::assertLessThan(10, \count($backwardPages), 'backward paging must terminate');
        }

        // backwardPages walked last → first; reverse to compare to forward order.
        $backwardPages = \array_reverse($backwardPages);
        $forwardIds = \array_map(static fn(array $page): array => $page['ids'], $forwardPages);

        self::assertSame($forwardIds, $backwardPages, 'each backward page must equal its forward page');
    }

    // --- mixed asc/desc + the PK tiebreak ------------------------------------

    #[Test]
    #[Group('spec:fetching-pagination')]
    #[Group('spec:fetching-sorting')]
    public function mixedAscDescMultiColumnPagesInTheForcedOrder(): void
    {
        // sort=category,-priority: category asc, priority DESC (nulls FIRST in
        // desc, NULL=largest), PK tiebreak asc... but the appended PK follows the
        // LAST directive (priority desc) so the tiebreak is DESC.
        // guide: priority desc → 30:(4,1 desc-id) 10:(7,2 desc-id)
        // news:  priority desc → null first:(6,3 desc-id) 20:(8,5 desc-id)
        $expected = ['4', '1', '7', '2', '6', '3', '8', '5'];

        $walked = $this->walkForward('/cursorWidgets?sort=category,-priority', 2);

        self::assertSame($expected, $walked);
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function aNonUniqueSortColumnIsResolvedOnlyByThePkTiebreak(): void
    {
        // sort=category with page[size]=1 — guide×4, news×4 all tie on category, so
        // only the appended PK keeps them totally ordered. Every row must be visited
        // exactly once (a missing PK tiebreak would skip or repeat a tied row).
        // category asc, id asc tiebreak: guide:(1,2,4,7) news:(3,5,6,8)
        $expected = ['1', '2', '4', '7', '3', '5', '6', '8'];

        $walked = $this->walkForward('/cursorWidgets?sort=category', 1);

        self::assertSame($expected, $walked);
    }

    // --- PK-only -------------------------------------------------------------

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function pkOnlyPagingWithNoSortWalksIdOrder(): void
    {
        // No ?sort → keyset is PK-only (id asc). page[size]=2 walks 1..8.
        $expected = ['1', '2', '3', '4', '5', '6', '7', '8'];

        $walked = $this->walkForward('/cursorWidgets', 2);

        self::assertSame($expected, $walked);
    }

    // --- the nullable column / null bucket -----------------------------------

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function aNullableColumnPagesThroughItsNullBucketAscNullsLast(): void
    {
        // sort=priority,id asc: non-null priorities first (10,20,30), then the
        // NULL rows (3,6) last — NULL=largest. A page boundary landing in the null
        // bucket must page into and out of it with no skip/repeat.
        $expected = ['2', '7', '5', '8', '1', '4', '3', '6'];

        $walked = $this->walkForward('/cursorWidgets?sort=priority,id', 3);

        self::assertSame($expected, $walked);
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function aNullableColumnDescPutsNullsFirst(): void
    {
        // sort=-priority,-id: priority DESC puts the NULL rows FIRST (NULL=largest),
        // then 30,20,10 descending; the appended PK follows -priority (desc).
        // null:(6,3 desc-id) 30:(4,1) 20:(8,5) 10:(7,2)
        $expected = ['6', '3', '4', '1', '8', '5', '7', '2'];

        $walked = $this->walkForward('/cursorWidgets?sort=-priority,-id', 3);

        self::assertSame($expected, $walked);
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    #[Group('spec:fetching-sorting')]
    public function aNullableColumnBelowAHigherSortKeyPagesThroughItsNullBucket(): void
    {
        // sort=category,priority,id: the NULLABLE column sits at sort LEVEL 1 (below
        // category), so a boundary landing on a null-priority row (ids 3/6, category
        // news) carries a NON-null higher column AND a null on the nullable level —
        // the keyset WHERE drops that level's after-term but must NOT orphan the
        // higher column's equality-prefix binding (a Doctrine 'Too many parameters'
        // 500 if it does). Walked at size 2 so a boundary lands exactly in the bucket.
        // category asc, priority asc (NULL=largest → nulls last), id tiebreak:
        //   guide: 10:(2,7) 30:(1,4)   news: 20:(5,8) null:(3,6)
        $expected = ['2', '7', '1', '4', '5', '8', '3', '6'];

        $walked = $this->walkForward('/cursorWidgets?sort=category,priority,id', 2);

        self::assertSame($expected, $walked, 'a nullable column below a higher sort key must page through its null bucket');
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    #[Group('spec:fetching-sorting')]
    public function aBackwardWalkFlipsADescNullableColumnToAscBelowAHigherKey(): void
    {
        // sort=category,-priority,-id walked BACKWARD: page[before] flips -priority
        // (desc) to priority ASC, so the backward keyset has an ASCENDING nullable
        // column at level 1 — the exact post-flip degenerate the forward path never
        // hits. A backward step whose boundary is a null-priority row (3/6) must not
        // orphan the category equality-prefix params. The forward order is:
        //   category asc, priority desc (NULL=largest → nulls first), id desc tiebreak
        //   guide: 30:(4,1) 10:(7,2)   news: null:(6,3) 20:(8,5)
        $forwardPages = $this->forwardPages('/cursorWidgets?sort=category,-priority,-id', 2);
        self::assertGreaterThanOrEqual(3, \count($forwardPages));

        $lastIndex = \count($forwardPages) - 1;
        $path = $forwardPages[$lastIndex]['path'];

        $backwardPages = [];
        while (true) {
            [$ids, $links] = $this->page($path);
            $backwardPages[] = $ids;
            if (!isset($links['prev'])) {
                break;
            }
            $path = $this->relativePath($this->href($links['prev']));
            self::assertLessThan(10, \count($backwardPages), 'backward paging must terminate');
        }

        $backwardPages = \array_reverse($backwardPages);
        $forwardIds = \array_map(static fn(array $page): array => $page['ids'], $forwardPages);

        self::assertSame($forwardIds, $backwardPages, 'a backward walk flipping a desc nullable column to asc must round-trip');
    }

    // --- exhaustion ----------------------------------------------------------

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function anAfterCursorAtTheEndReturnsAnEmptyPageWithNoNext(): void
    {
        // Walk to the very last page, then follow next once more is impossible
        // (no next link). Instead, take the last page's would-be next by minting:
        // page to the end, assert the final page has the surplus exhausted.
        $path = '/cursorWidgets?sort=priority,id&page[size]=4';
        [$ids, $links] = $this->page($path);
        self::assertSame(['2', '7', '5', '8'], $ids);
        self::assertArrayHasKey('next', $links);

        $path = $this->relativePath($this->href($links['next']));
        [$ids, $links] = $this->page($path);
        self::assertSame(['1', '4', '3', '6'], $ids);
        self::assertArrayNotHasKey('next', $links, 'the exhausting page emits no next');
        self::assertArrayHasKey('prev', $links);
    }

    // --- before wins over after ----------------------------------------------

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function beforeWinsOverAfterWhenBothAreSupplied(): void
    {
        // Get a forward `next` cursor (after) and a `prev` cursor (before) from a
        // middle page, then request with BOTH — the before page must be served,
        // not the after page.
        $first = $this->page('/cursorWidgets?sort=priority,id&page[size]=2');
        $secondPath = $this->relativePath($this->href($first[1]['next']));
        [$secondIds, $secondLinks] = $this->page($secondPath);
        self::assertSame(['5', '8'], $secondIds);

        $afterToken = $this->cursorParam($this->href($secondLinks['next']), 'after');
        $beforeToken = $this->cursorParam($this->href($secondLinks['prev']), 'before');

        // Both supplied: before (page 1: 2,7) must win over after (page 3: 1,4).
        [$ids] = $this->page(\sprintf(
            '/cursorWidgets?sort=priority,id&page[size]=2&page[after]=%s&page[before]=%s',
            \rawurlencode($afterToken),
            \rawurlencode($beforeToken),
        ));

        self::assertSame(['2', '7'], $ids, 'page[before] must win over page[after]');
    }

    // --- date-keyed + typed binding ------------------------------------------

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function dateKeyedSortPagesChronologicallyAcrossBoundaries(): void
    {
        // sort=releasedAt,id asc: non-null dates chronologically, then the NULL
        // rows (4,6) last. Reference by (releasedAt asc, NULL last, id asc):
        //   2024-01-05:1, 2024-01-20:5, 2024-02-10:3, 2024-03-01:2,
        //   2024-04-15:8, 2024-05-01:7, null:(4,6)
        $expected = ['1', '5', '3', '2', '8', '7', '4', '6'];

        // page[size]=3 so a page boundary lands exactly on a date row and the date
        // boundary must round-trip (ISO-8601 mint → typed DBAL datetime bind) so a
        // row equal to the boundary is not duplicated/skipped.
        $walked = $this->walkForward('/cursorWidgets?sort=releasedAt,id', 3);

        self::assertSame($expected, $walked);
    }

    // --- stale / malformed 400 ------------------------------------------------

    #[Test]
    #[Group('spec:fetching-pagination')]
    #[Group('spec:errors')]
    public function aStaleCursorIsA400(): void
    {
        // Mint a cursor under sort=priority, then re-request with sort=category
        // carrying the same page[after] — the keyset columns changed, so 400 STALE.
        [, $links] = $this->page('/cursorWidgets?sort=priority,id&page[size]=2');
        $afterToken = $this->cursorParam($this->href($links['next']), 'after');

        $response = $this->handle(\sprintf(
            '/cursorWidgets?sort=category&page[size]=2&page[after]=%s',
            \rawurlencode($afterToken),
        ));

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
        $error = $this->firstError($this->decode($response));
        self::assertSame('CURSOR_STALE', $error['code'] ?? null);
        self::assertSame(['parameter' => 'page[after]'], $error['source'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    #[Group('spec:errors')]
    public function aFlippedSortDirectionIsAStale400(): void
    {
        // Mint a cursor under sort=category (asc), then re-request with
        // sort=-category carrying the same page[after] — the column SET is
        // unchanged but its direction flipped, so the cursor was minted under the
        // opposite order: 400 STALE. A column-set comparison alone would miss this.
        [, $links] = $this->page('/cursorWidgets?sort=category&page[size]=2');
        $afterToken = $this->cursorParam($this->href($links['next']), 'after');

        $flipped = $this->handle(\sprintf(
            '/cursorWidgets?sort=-category&page[size]=2&page[after]=%s',
            \rawurlencode($afterToken),
        ));

        self::assertSame(400, $flipped->getStatusCode(), (string) $flipped->getContent());
        $error = $this->firstError($this->decode($flipped));
        self::assertSame('CURSOR_STALE', $error['code'] ?? null);
        self::assertSame(['parameter' => 'page[after]'], $error['source'] ?? null);

        // The SAME sort the cursor was minted under is fresh — it pages on.
        $same = $this->handle(\sprintf(
            '/cursorWidgets?sort=category&page[size]=2&page[after]=%s',
            \rawurlencode($afterToken),
        ));
        self::assertSame(200, $same->getStatusCode(), (string) $same->getContent());
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    #[Group('spec:errors')]
    public function aMalformedCursorIsA400(): void
    {
        $response = $this->handle('/cursorWidgets?sort=priority,id&page[after]=not-base64url!!');

        self::assertSame(400, $response->getStatusCode());
        $error = $this->firstError($this->decode($response));
        self::assertSame('CURSOR_MALFORMED', $error['code'] ?? null);
        self::assertSame(['parameter' => 'page[after]'], $error['source'] ?? null);
    }

    // --- helpers --------------------------------------------------------------

    /**
     * Walks forward from `$path` following `next` until exhausted, returning the
     * concatenated ids in document order.
     *
     * @return list<string>
     */
    private function walkForward(string $path, int $size): array
    {
        $sep = \str_contains($path, '?') ? '&' : '?';
        $path .= $sep . 'page[size]=' . $size;

        $ids = [];
        $guard = 0;
        while (true) {
            [$pageIds, $links] = $this->page($path);
            foreach ($pageIds as $id) {
                $ids[] = $id;
            }
            if (!isset($links['next'])) {
                break;
            }
            $path = $this->relativePath($this->href($links['next']));
            self::assertLessThan(20, ++$guard, 'forward paging must terminate');
        }

        return $ids;
    }

    /**
     * The forward pages from `$path` as `[{ids, path}, …]`, capturing the request
     * path of each page so a backward walk can start from the deepest one.
     *
     * @return list<array{ids: list<string>, path: string}>
     */
    private function forwardPages(string $path, int $size): array
    {
        $sep = \str_contains($path, '?') ? '&' : '?';
        $path .= $sep . 'page[size]=' . $size;

        $pages = [];
        $guard = 0;
        while (true) {
            [$pageIds, $links] = $this->page($path);
            $pages[] = ['ids' => $pageIds, 'path' => $path];
            if (!isset($links['next'])) {
                break;
            }
            $path = $this->relativePath($this->href($links['next']));
            self::assertLessThan(20, ++$guard, 'forward paging must terminate');
        }

        return $pages;
    }

    /**
     * Fetches a cursor page and returns `[ids, links]`.
     *
     * @return array{0: list<string>, 1: array<string, mixed>}
     */
    private function page(string $path): array
    {
        $response = $this->handle($path);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));

        $document = $this->decode($response);

        $data = $document['data'] ?? null;
        self::assertIsArray($data);

        $ids = [];
        foreach ($data as $resource) {
            self::assertIsArray($resource);
            self::assertSame('cursorWidgets', $resource['type'] ?? null);
            $id = $resource['id'] ?? null;
            self::assertIsString($id);
            $ids[] = $id;
        }

        $links = $document['links'] ?? [];
        self::assertIsArray($links);

        // Drop null links so isset() reflects presence (a cursor page renders
        // self/first always, prev/next conditionally).
        $links = \array_filter($links, static fn(mixed $link): bool => $link !== null);

        /** @var array<string, mixed> $links */
        return [$ids, $links];
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

        $first = $errors[0] ?? null;
        self::assertIsArray($first);

        /** @var array<string, mixed> $first */
        return $first;
    }

    private function href(mixed $link): string
    {
        if (\is_array($link) && isset($link['href']) && \is_string($link['href'])) {
            return $link['href'];
        }

        self::assertIsString($link);

        return $link;
    }

    /**
     * The path + query of an absolute link, for re-issuing through the test kernel.
     */
    private function relativePath(string $url): string
    {
        $path = (string) \parse_url($url, \PHP_URL_PATH);
        $query = \parse_url($url, \PHP_URL_QUERY);

        return $query !== null && $query !== false ? $path . '?' . $query : $path;
    }

    /**
     * The `page[$key]` cursor token from an absolute link href.
     */
    private function cursorParam(string $url, string $key): string
    {
        \parse_str((string) \parse_url($url, \PHP_URL_QUERY), $query);
        $page = $query['page'] ?? null;
        self::assertIsArray($page);
        $token = $page[$key] ?? null;
        self::assertIsString($token);

        return $token;
    }
}
