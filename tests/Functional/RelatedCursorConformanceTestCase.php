<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The RELATED-collection cursor (keyset) pagination acceptance suite, asserted
 * byte-identical on the in-memory ({@see InMemoryRelatedCursorTest}) and
 * Doctrine-sqlite ({@see DoctrineRelatedCursorTest}) kernels over the shared
 * `cursorShelves` → `widgets` declaration (the relation declares its OWN
 * {@see \haddowg\JsonApi\Pagination\CursorPaginator}) and the
 * {@see \haddowg\JsonApiBundle\Tests\Functional\App\CursorShelfFixtures} seed.
 * The in-memory witness is the ground truth; the Doctrine keyset push-down —
 * running INSIDE the RelationScope parent scope — must match it (bundle ADR
 * 0063).
 *
 * Shelf 1 holds every widget, so its related pages must equal the primary
 * `/cursorWidgets` keyset pages verbatim (the {@see CursorConformanceTestCase}
 * reference orders); shelf 2 holds only the `news` rows, so a walk over it
 * proves the keyset executes inside the parent scope (a leaked non-member is
 * immediately visible) while still paging through the null bucket. The cursor
 * links are scoped to the related URL, `last` is never rendered (count-free by
 * design), and `meta.page` carries the cursor shape (`perPage`/`from`/`to`/
 * `hasMore`, never a total).
 */
abstract class RelatedCursorConformanceTestCase extends JsonApiFunctionalTestCase
{
    // --- forward / backward round-trips --------------------------------------

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function forwardPagingWalksTheWholeRelatedCollectionInKeysetOrder(): void
    {
        // sort=priority,id over shelf 1 (every widget): the SAME reference order
        // as the primary /cursorWidgets walk — priority asc (nulls last,
        // NULL=largest), id tiebreak: 10:(2,7) 20:(5,8) 30:(1,4) null:(3,6).
        $expected = ['2', '7', '5', '8', '1', '4', '3', '6'];

        $walked = $this->walkForward('/cursorShelves/1/widgets?sort=priority,id', 2);

        self::assertSame($expected, $walked, 'forward paging must visit every related row once in keyset order');
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function relatedPagesAreScopedToTheParent(): void
    {
        // Shelf 2 holds only the news widgets (3, 5, 6, 8). The keyset walk under
        // sort=priority,id must page ONLY those — 20:(5,8) then the null bucket
        // (3,6) — proving the keyset WHERE composes with the parent scope rather
        // than paging the whole widget table.
        $expected = ['5', '8', '3', '6'];

        $walked = $this->walkForward('/cursorShelves/2/widgets?sort=priority,id', 2);

        self::assertSame($expected, $walked, 'the related keyset walk must stay inside the parent scope');
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function pkOnlyPagingWithNoSortWalksIdOrderInsideTheParentScope(): void
    {
        // No ?sort → keyset is PK-only (id asc), scoped to shelf 2's members.
        $expected = ['3', '5', '6', '8'];

        $walked = $this->walkForward('/cursorShelves/2/widgets', 2);

        self::assertSame($expected, $walked);
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function backwardPagingFromADeepPageEqualsTheForwardPages(): void
    {
        // Collect the forward pages, then from the deepest page follow `prev`
        // repeatedly; each backward page must equal the corresponding forward page
        // (same ids, same forward order) — the flip+slice+reverse round-trip, on
        // the related endpoint.
        $forwardPages = $this->forwardPages('/cursorShelves/1/widgets?sort=priority,id', 2);
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

        // backwardPages walked last → first; reverse to compare to forward order.
        $backwardPages = \array_reverse($backwardPages);
        $forwardIds = \array_map(static fn(array $page): array => $page['ids'], $forwardPages);

        self::assertSame($forwardIds, $backwardPages, 'each backward page must equal its forward page');
    }

    // --- links: related-URL scoping, no `last` --------------------------------

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function cursorLinksAreScopedToTheRelatedUrlAndNeverRenderLast(): void
    {
        $relatedUrl = 'https://example.test/cursorShelves/1/widgets';

        [$ids, $links] = $this->page('/cursorShelves/1/widgets?sort=priority,id&page[size]=2');
        self::assertSame(['2', '7'], $ids);

        // The head page: first + next, no prev (head of the list), no last (a
        // cursor page never derives a total).
        self::assertArrayHasKey('first', $links);
        self::assertArrayHasKey('next', $links);
        self::assertArrayNotHasKey('prev', $links);
        self::assertArrayNotHasKey('last', $links);

        self::assertStringStartsWith($relatedUrl . '?', $this->href($links['first']));
        self::assertStringStartsWith($relatedUrl . '?', $this->href($links['next']));

        // The next link carries the minted page[after] cursor at the related URL.
        self::assertNotSame('', $this->cursorParam($this->href($links['next']), 'after'));

        // A deep page keeps every link on the related URL and still omits `last`.
        [, $links] = $this->page($this->relativePath($this->href($links['next'])));
        self::assertArrayHasKey('prev', $links);
        self::assertArrayNotHasKey('last', $links);
        self::assertStringStartsWith($relatedUrl . '?', $this->href($links['prev']));
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function theFinalForwardPageHasNoNextLink(): void
    {
        $path = '/cursorShelves/2/widgets?sort=priority,id&page[size]=2';
        [$ids, $links] = $this->page($path);
        self::assertSame(['5', '8'], $ids);
        self::assertArrayHasKey('next', $links);

        $path = $this->relativePath($this->href($links['next']));
        [$ids, $links] = $this->page($path);
        self::assertSame(['3', '6'], $ids);
        self::assertArrayNotHasKey('next', $links, 'the exhausting page emits no next');
        self::assertArrayHasKey('prev', $links);
    }

    // --- meta.page + media type ------------------------------------------------

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function metaPageRendersTheCountFreeCursorShape(): void
    {
        $response = $this->handle('/cursorShelves/1/widgets?sort=priority,id&page[size]=2');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $document = $this->decode($response);
        $meta = $document['meta'] ?? null;
        self::assertIsArray($meta);

        // The cursor page meta: perPage + the first/last wire ids + hasMore —
        // and NEVER a total (count-free by design), neither in meta.page nor at
        // the top level.
        $page = $meta['page'] ?? null;
        self::assertIsArray($page);
        self::assertSame(2, $page['perPage'] ?? null);
        self::assertSame('2', $page['from'] ?? null);
        self::assertSame('7', $page['to'] ?? null);
        self::assertTrue($page['hasMore'] ?? null);
        self::assertArrayNotHasKey('total', $page);
        self::assertArrayNotHasKey('total', $meta);
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    #[Group('spec:profiles')]
    public function theRelatedCursorPageAdvertisesItsMediaTypeLikeThePrimary(): void
    {
        // The cursor-pagination profile is advertised by core's shared page
        // rendering iff the SERVER registers it (an unregistered page profile is
        // silently dropped) — so the related endpoint's advertisement must be
        // byte-identical to the primary cursor collection's, whatever the server's
        // profile registry says.
        $primary = $this->handle('/cursorWidgets?sort=priority,id&page[size]=2');
        $related = $this->handle('/cursorShelves/1/widgets?sort=priority,id&page[size]=2');

        self::assertSame(200, $primary->getStatusCode());
        self::assertSame(200, $related->getStatusCode());
        self::assertSame(
            $primary->headers->get('Content-Type'),
            $related->headers->get('Content-Type'),
            'the related cursor page must advertise the same media type (incl. any profile param) as the primary',
        );

        $primaryJsonApi = $this->decode($primary)['jsonapi'] ?? null;
        $relatedJsonApi = $this->decode($related)['jsonapi'] ?? null;
        self::assertSame($primaryJsonApi, $relatedJsonApi, 'jsonapi (incl. profile) must match the primary cursor page');
    }

    // --- before wins over after ------------------------------------------------

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function beforeWinsOverAfterWhenBothAreSupplied(): void
    {
        $first = $this->page('/cursorShelves/1/widgets?sort=priority,id&page[size]=2');
        $secondPath = $this->relativePath($this->href($first[1]['next']));
        [$secondIds, $secondLinks] = $this->page($secondPath);
        self::assertSame(['5', '8'], $secondIds);

        $afterToken = $this->cursorParam($this->href($secondLinks['next']), 'after');
        $beforeToken = $this->cursorParam($this->href($secondLinks['prev']), 'before');

        // Both supplied: before (page 1: 2,7) must win over after (page 3: 1,4).
        [$ids] = $this->page(\sprintf(
            '/cursorShelves/1/widgets?sort=priority,id&page[size]=2&page[after]=%s&page[before]=%s',
            \rawurlencode($afterToken),
            \rawurlencode($beforeToken),
        ));

        self::assertSame(['2', '7'], $ids, 'page[before] must win over page[after]');
    }

    // --- stale / malformed 400 -------------------------------------------------

    #[Test]
    #[Group('spec:fetching-pagination')]
    #[Group('spec:errors')]
    public function aStaleCursorIsA400OnTheRelatedEndpoint(): void
    {
        // Mint a cursor under sort=priority,id, then re-request with sort=category
        // carrying the same page[after] — the keyset columns changed, so 400 STALE,
        // exactly as on the primary collection.
        [, $links] = $this->page('/cursorShelves/1/widgets?sort=priority,id&page[size]=2');
        $afterToken = $this->cursorParam($this->href($links['next']), 'after');

        $response = $this->handle(\sprintf(
            '/cursorShelves/1/widgets?sort=category&page[size]=2&page[after]=%s',
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
    public function aMalformedCursorIsA400OnTheRelatedEndpoint(): void
    {
        $response = $this->handle('/cursorShelves/1/widgets?sort=priority,id&page[after]=not-base64url!!');

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
     * Fetches a related cursor page and returns `[ids, links]`. Every rendered
     * member must be a `cursorWidgets` resource (the related type).
     *
     * @return array{0: list<string>, 1: array<string, mixed>}
     */
    private function page(string $path): array
    {
        $response = $this->handle($path);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

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
        // self/first always, prev/next conditionally, and never last).
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
