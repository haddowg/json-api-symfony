<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The relationship-LINKAGE cursor (keyset) pagination acceptance suite —
 * `GET /cursorShelves/{id}/relationships/widgets` under the relation's own
 * {@see \haddowg\JsonApi\Pagination\CursorPaginator} — asserted byte-identical
 * on the in-memory ({@see InMemoryLinkageCursorTest}) and Doctrine-sqlite
 * ({@see DoctrineLinkageCursorTest}) kernels over the shared `cursorShelves`
 * declaration and seed (bundle ADR 0114).
 *
 * The linkage endpoint windows the SAME keyset the related endpoint pages —
 * the members render as resource IDENTIFIERS, the boundary-cursor
 * `prev`/`next` links (`page[before]`/`page[after]`) ride the relationship
 * object's links at the relationship URL, and the response advertises the
 * cursor-pagination profile exactly as the related/primary pages do (core
 * ADR 0124: the page is attached to the linkage response for its PROFILE
 * only). The body stays links-only: no `meta.page`, and never a `last` link
 * (count-free by design).
 */
abstract class LinkageCursorConformanceTestCase extends JsonApiFunctionalTestCase
{
    // --- the linkage is identifiers, windowed to the keyset page ---------------

    #[Test]
    #[Group('spec:fetching-relationships')]
    #[Group('spec:fetching-pagination')]
    public function theLinkageWindowsToTheKeysetPageOfIdentifiers(): void
    {
        // sort=priority,id, size 2: the head keyset page is widgets 2, 7 (the same
        // reference order as the related/primary walks), rendered as identifiers.
        $document = $this->fetchDocument('/cursorShelves/1/relationships/widgets?sort=priority,id&page[size]=2');

        self::assertSame(
            [
                ['type' => 'cursorWidgets', 'id' => '2'],
                ['type' => 'cursorWidgets', 'id' => '7'],
            ],
            $document['data'] ?? null,
            'the linkage must be the keyset page-1 identifiers, nothing more',
        );
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function forwardPagingWalksTheWholeLinkageInKeysetOrder(): void
    {
        // Follow next through the whole set: the concatenated identifier ids must
        // equal the related endpoint's keyset walk verbatim.
        $expected = ['2', '7', '5', '8', '1', '4', '3', '6'];

        $walked = $this->walkForward('/cursorShelves/1/relationships/widgets?sort=priority,id', 2);

        self::assertSame($expected, $walked, 'forward paging must visit every linkage member once in keyset order');
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function linkagePagesAreScopedToTheParent(): void
    {
        // Shelf 2 holds only the news widgets: the linkage walk pages exactly those.
        $expected = ['5', '8', '3', '6'];

        $walked = $this->walkForward('/cursorShelves/2/relationships/widgets?sort=priority,id', 2);

        self::assertSame($expected, $walked, 'the linkage keyset walk must stay inside the parent scope');
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function aBackwardLinkagePageEqualsItsForwardPage(): void
    {
        // Page 2's prev carries the minted page[before] cursor; following it must
        // reproduce page 1 exactly (the flip+slice+reverse round-trip).
        [$firstIds, $links] = $this->page('/cursorShelves/1/relationships/widgets?sort=priority,id&page[size]=2');
        self::assertSame(['2', '7'], $firstIds);

        [, $secondLinks] = $this->page($this->relativePath($this->href($links['next'])));
        self::assertNotSame('', $this->cursorParam($this->href($secondLinks['prev']), 'before'));

        [$backIds] = $this->page($this->relativePath($this->href($secondLinks['prev'])));

        self::assertSame($firstIds, $backIds, 'the backward linkage page must equal its forward page');
    }

    // --- links: relationship-URL scoping, cursors, no `last` -------------------

    #[Test]
    #[Group('spec:fetching-relationships')]
    #[Group('spec:fetching-pagination')]
    public function cursorLinksAreScopedToTheRelationshipUrlAndNeverRenderLast(): void
    {
        $relationshipUrl = 'https://example.test/cursorShelves/1/relationships/widgets';

        [, $links] = $this->page('/cursorShelves/1/relationships/widgets?sort=priority,id&page[size]=2');

        // The head page: first + next (with the minted page[after]), no prev, and
        // never a last (a cursor page derives no total).
        self::assertArrayHasKey('first', $links);
        self::assertArrayHasKey('next', $links);
        self::assertArrayNotHasKey('prev', $links);
        self::assertArrayNotHasKey('last', $links);

        self::assertStringStartsWith($relationshipUrl . '?', $this->href($links['first']));
        self::assertStringStartsWith($relationshipUrl . '?', $this->href($links['next']));
        self::assertNotSame('', $this->cursorParam($this->href($links['next']), 'after'));

        // The bare convention self stays the endpoint URL.
        self::assertSame($relationshipUrl, isset($links['self']) ? $this->href($links['self']) : null);

        // A deep page keeps every link on the relationship URL and still omits last.
        [, $links] = $this->page($this->relativePath($this->href($links['next'])));
        self::assertArrayHasKey('prev', $links);
        self::assertArrayNotHasKey('last', $links);
        self::assertStringStartsWith($relationshipUrl . '?', $this->href($links['prev']));
    }

    // --- the body stays links-only ---------------------------------------------

    #[Test]
    #[Group('spec:fetching-relationships')]
    #[Group('spec:fetching-pagination')]
    public function theLinkageBodyCarriesNoPageMeta(): void
    {
        // A linkage document is links-only (core ADR 0124): the windowing page
        // feeds the links and the profile, never a meta.page (and no meta.total —
        // a cursor page is count-free by design).
        $document = $this->fetchDocument('/cursorShelves/1/relationships/widgets?sort=priority,id&page[size]=2');

        $meta = $document['meta'] ?? null;
        if (\is_array($meta)) {
            self::assertArrayNotHasKey('page', $meta, 'linkage pagination rides links, not meta.page');
            self::assertArrayNotHasKey('total', $meta, 'a cursor linkage page never carries a total');
        } else {
            self::assertNull($meta);
        }
    }

    // --- media type / profile ----------------------------------------------------

    #[Test]
    #[Group('spec:fetching-pagination')]
    #[Group('spec:profiles')]
    public function theCursorLinkagePageAdvertisesItsMediaTypeLikeThePrimary(): void
    {
        // The cursor-pagination profile is advertised by the attached page iff the
        // SERVER registers it — so the linkage endpoint's advertisement must be
        // byte-identical to the primary cursor collection's, whatever the server's
        // profile registry says (core ADR 0124).
        $primary = $this->handle('/cursorWidgets?sort=priority,id&page[size]=2');
        $linkage = $this->handle('/cursorShelves/1/relationships/widgets?sort=priority,id&page[size]=2');

        self::assertSame(200, $primary->getStatusCode());
        self::assertSame(200, $linkage->getStatusCode());
        self::assertSame(
            $primary->headers->get('Content-Type'),
            $linkage->headers->get('Content-Type'),
            'the cursor linkage page must advertise the same media type (incl. any profile param) as the primary',
        );

        $primaryJsonApi = $this->decode($primary)['jsonapi'] ?? null;
        $linkageJsonApi = $this->decode($linkage)['jsonapi'] ?? null;
        self::assertSame($primaryJsonApi, $linkageJsonApi, 'jsonapi (incl. profile) must match the primary cursor page');
    }

    // --- stale / malformed 400 -------------------------------------------------

    #[Test]
    #[Group('spec:fetching-pagination')]
    #[Group('spec:errors')]
    public function aStaleCursorIsA400OnTheLinkageEndpoint(): void
    {
        // A token minted under sort=priority,id re-used under sort=category changed
        // the keyset columns — 400 STALE, exactly as on the related endpoint.
        [, $links] = $this->page('/cursorShelves/1/relationships/widgets?sort=priority,id&page[size]=2');
        $afterToken = $this->cursorParam($this->href($links['next']), 'after');

        $response = $this->handle(\sprintf(
            '/cursorShelves/1/relationships/widgets?sort=category&page[size]=2&page[after]=%s',
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
    public function aMalformedCursorIsA400OnTheLinkageEndpoint(): void
    {
        $response = $this->handle('/cursorShelves/1/relationships/widgets?sort=priority,id&page[after]=not-base64url!!');

        self::assertSame(400, $response->getStatusCode());
        $error = $this->firstError($this->decode($response));
        self::assertSame('CURSOR_MALFORMED', $error['code'] ?? null);
        self::assertSame(['parameter' => 'page[after]'], $error['source'] ?? null);
    }

    // --- helpers --------------------------------------------------------------

    /**
     * Fetches `$path` (a 200) and returns the decoded document.
     *
     * @return array<string, mixed>
     */
    protected function fetchDocument(string $path): array
    {
        $response = $this->handle($path);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return $this->decode($response);
    }

    /**
     * Walks forward from `$path` following `next` until exhausted, returning the
     * concatenated identifier ids in document order.
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
     * Fetches a linkage cursor page and returns `[ids, links]`. Every rendered
     * member must be a `cursorWidgets` resource IDENTIFIER (the related type).
     *
     * @return array{0: list<string>, 1: array<string, mixed>}
     */
    protected function page(string $path): array
    {
        $document = $this->fetchDocument($path);

        $data = $document['data'] ?? null;
        self::assertIsArray($data);

        $ids = [];
        foreach ($data as $identifier) {
            self::assertIsArray($identifier);
            self::assertSame('cursorWidgets', $identifier['type'] ?? null);
            $id = $identifier['id'] ?? null;
            self::assertIsString($id);
            $ids[] = $id;
        }

        $links = $document['links'] ?? [];
        self::assertIsArray($links);

        // Drop null links so isset() reflects presence (self/related/first always,
        // prev/next conditionally, and never last on a cursor page).
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
