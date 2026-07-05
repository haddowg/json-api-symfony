<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\CursorShelfFixtures;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The PIVOT-related-collection cursor (keyset) pagination acceptance suite over
 * the shared `cursorShelves` → `pivotWidgets` declaration (a `belongsToMany`
 * carrying a `slot` pivot field, the relation declaring its OWN
 * {@see \haddowg\JsonApi\Pagination\CursorPaginator}) and the
 * {@see \haddowg\JsonApiBundle\Tests\Functional\App\CursorShelfFixtures} seed —
 * bundle ADR 0114.
 *
 * The page-walk assertions are byte-identical on the in-memory
 * ({@see InMemoryPivotRelatedCursorTest}) and Doctrine-sqlite
 * ({@see DoctrinePivotRelatedCursorTest}) kernels: shelf 1 holds every widget,
 * so a widget-attribute keyset walk must equal the primary `/cursorWidgets`
 * reference orders; shelf 2 holds only the `news` rows, proving the keyset runs
 * inside the parent scope. Where the two providers DIFFER is the documented
 * pivot boundary ({@see expectsPivotMeta()}): the Doctrine kernel executes the
 * fetch over the association entity — every member renders `meta.pivot` and
 * `?sort=slot` resolves (the Doctrine subclass walks the pivot-aliased keyset)
 * — while the in-memory provider is not pivot-aware, so the same request pages
 * through the PLAIN keyset with no pivot meta and `?sort=slot` stays a 400.
 *
 * Cursor pages are count-free by design: no `last` link, no total, `meta.page`
 * in the cursor shape, and the cursor-pagination profile advertised exactly as
 * the primary collection advertises it.
 */
abstract class PivotRelatedCursorConformanceTestCase extends JsonApiFunctionalTestCase
{
    /**
     * Whether this kernel's provider is pivot-aware (the Doctrine reference): the
     * fetch runs over the association entity, so every member carries
     * `meta.pivot.slot`. False on the in-memory kernel — the documented pivot
     * boundary: the same declaration pages through the plain keyset, pivot-less.
     */
    abstract protected function expectsPivotMeta(): bool;

    // --- forward / backward round-trips --------------------------------------

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function forwardPagingWalksTheWholePivotRelatedCollectionInKeysetOrder(): void
    {
        // sort=priority,id over shelf 1 (every widget): the SAME reference order
        // as the primary /cursorWidgets walk — priority asc (nulls last,
        // NULL=largest), id tiebreak: 10:(2,7) 20:(5,8) 30:(1,4) null:(3,6).
        $expected = ['2', '7', '5', '8', '1', '4', '3', '6'];

        $walked = $this->walkForward('/cursorShelves/1/pivotWidgets?sort=priority,id', 2);

        self::assertSame($expected, $walked, 'forward paging must visit every pivot-related row once in keyset order');
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function pivotRelatedPagesAreScopedToTheParent(): void
    {
        // Shelf 2 holds only the news widgets (3, 5, 6, 8). The keyset walk under
        // sort=priority,id must page ONLY those — 20:(5,8) then the null bucket
        // (3,6) — proving the keyset WHERE composes with the parent scope.
        $expected = ['5', '8', '3', '6'];

        $walked = $this->walkForward('/cursorShelves/2/pivotWidgets?sort=priority,id', 2);

        self::assertSame($expected, $walked, 'the pivot keyset walk must stay inside the parent scope');
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function backwardPagingFromADeepPageEqualsTheForwardPages(): void
    {
        // Collect the forward pages, then from the deepest page follow `prev`
        // repeatedly; each backward page must equal the corresponding forward page
        // (same ids, same forward order) — the flip+slice+reverse round-trip, on
        // the pivot related endpoint.
        $forwardPages = $this->forwardPages('/cursorShelves/1/pivotWidgets?sort=priority,id', 2);
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

        self::assertSame($forwardIds, $backwardPages, 'each backward page must equal its forward page');
    }

    // --- links: related-URL scoping, no `last` --------------------------------

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function cursorLinksAreScopedToThePivotRelatedUrlAndNeverRenderLast(): void
    {
        $relatedUrl = 'https://example.test/cursorShelves/1/pivotWidgets';

        [$ids, $links] = $this->page('/cursorShelves/1/pivotWidgets?sort=priority,id&page[size]=2');
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

        // A deep page keeps every link on the related URL, carries the minted
        // page[before] cursor on prev, and still omits `last`.
        [, $links] = $this->page($this->relativePath($this->href($links['next'])));
        self::assertArrayHasKey('prev', $links);
        self::assertArrayNotHasKey('last', $links);
        self::assertStringStartsWith($relatedUrl . '?', $this->href($links['prev']));
        self::assertNotSame('', $this->cursorParam($this->href($links['prev']), 'before'));
    }

    // --- meta.page + media type ------------------------------------------------

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function metaPageRendersTheCountFreeCursorShape(): void
    {
        $response = $this->handle('/cursorShelves/1/pivotWidgets?sort=priority,id&page[size]=2');
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
    public function thePivotRelatedCursorPageAdvertisesItsMediaTypeLikeThePrimary(): void
    {
        // The cursor-pagination profile is advertised by core's shared page
        // rendering iff the SERVER registers it — so the pivot related endpoint's
        // advertisement must be byte-identical to the primary cursor collection's,
        // whatever the server's profile registry says.
        $primary = $this->handle('/cursorWidgets?sort=priority,id&page[size]=2');
        $related = $this->handle('/cursorShelves/1/pivotWidgets?sort=priority,id&page[size]=2');

        self::assertSame(200, $primary->getStatusCode());
        self::assertSame(200, $related->getStatusCode());
        self::assertSame(
            $primary->headers->get('Content-Type'),
            $related->headers->get('Content-Type'),
            'the pivot related cursor page must advertise the same media type (incl. any profile param) as the primary',
        );

        $primaryJsonApi = $this->decode($primary)['jsonapi'] ?? null;
        $relatedJsonApi = $this->decode($related)['jsonapi'] ?? null;
        self::assertSame($primaryJsonApi, $relatedJsonApi, 'jsonapi (incl. profile) must match the primary cursor page');
    }

    // --- pivot meta: the provider boundary --------------------------------------

    #[Test]
    #[Group('spec:fetching-relationships')]
    #[Group('spec:fetching-pagination')]
    public function everyPageMemberHonoursThePivotMetaBoundary(): void
    {
        // Walk a full page: on the pivot-aware (Doctrine) kernel every member
        // carries its association row's `meta.pivot.slot`; on the in-memory kernel
        // the SAME declaration pages pivot-less (the documented boundary).
        $response = $this->handle('/cursorShelves/2/pivotWidgets?sort=priority,id&page[size]=2');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);
        self::assertNotSame([], $data);

        $slots = CursorShelfFixtures::slots();
        foreach ($data as $resource) {
            self::assertIsArray($resource);
            $meta = $resource['meta'] ?? [];
            self::assertIsArray($meta);

            if (!$this->expectsPivotMeta()) {
                self::assertArrayNotHasKey('pivot', $meta, 'the in-memory provider is not pivot-aware: no pivot meta renders');

                continue;
            }

            $id = $resource['id'] ?? null;
            self::assertIsString($id);
            self::assertSame(
                ['slot' => $slots[(int) $id]],
                $meta['pivot'] ?? null,
                \sprintf('member %s must carry its association row\'s pivot values', $id),
            );
        }
    }

    // --- stale / malformed 400 -------------------------------------------------

    #[Test]
    #[Group('spec:fetching-pagination')]
    #[Group('spec:errors')]
    public function aStaleCursorIsA400OnThePivotRelatedEndpoint(): void
    {
        // Mint a cursor under sort=priority,id, then re-request with sort=category
        // carrying the same page[after] — the keyset columns changed, so 400 STALE.
        [, $links] = $this->page('/cursorShelves/1/pivotWidgets?sort=priority,id&page[size]=2');
        $afterToken = $this->cursorParam($this->href($links['next']), 'after');

        $response = $this->handle(\sprintf(
            '/cursorShelves/1/pivotWidgets?sort=category&page[size]=2&page[after]=%s',
            \rawurlencode($afterToken),
        ));

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
        $error = $this->firstError($this->decode($response));
        self::assertSame('CURSOR_STALE', $error['code'] ?? null);
        self::assertSame(['parameter' => 'page[after]'], $error['source'] ?? null);
    }

    // --- helpers --------------------------------------------------------------

    /**
     * Walks forward from `$path` following `next` until exhausted, returning the
     * concatenated ids in document order.
     *
     * @return list<string>
     */
    protected function walkForward(string $path, int $size): array
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
    protected function forwardPages(string $path, int $size): array
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
     * Fetches a pivot related cursor page and returns `[ids, links]`. Every
     * rendered member must be a `cursorWidgets` resource (the related type).
     *
     * @return array{0: list<string>, 1: array<string, mixed>}
     */
    protected function page(string $path): array
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
    protected function firstError(array $document): array
    {
        $errors = $document['errors'] ?? null;
        self::assertIsArray($errors);
        self::assertNotEmpty($errors);

        $first = $errors[0] ?? null;
        self::assertIsArray($first);

        /** @var array<string, mixed> $first */
        return $first;
    }

    protected function href(mixed $link): string
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
    protected function relativePath(string $url): string
    {
        $path = (string) \parse_url($url, \PHP_URL_PATH);
        $query = \parse_url($url, \PHP_URL_QUERY);

        return $query !== null && $query !== false ? $path . '?' . $query : $path;
    }

    /**
     * The `page[$key]` cursor token from an absolute link href.
     */
    protected function cursorParam(string $url, string $key): string
    {
        \parse_str((string) \parse_url($url, \PHP_URL_QUERY), $query);
        $page = $query['page'] ?? null;
        self::assertIsArray($page);
        $token = $page[$key] ?? null;
        self::assertIsString($token);

        return $token;
    }
}
