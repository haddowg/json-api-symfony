<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Tests;

use Composer\InstalledVersions;
use haddowg\JsonApi\Pagination\CursorPaginationProfile;
use haddowg\JsonApiBundle\Examples\MusicCatalog\DataFixtures\SeedManifest;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The pagination-menu witness: `TrackResource::pagination()` offers page, cursor and
 * offset strategies on `GET /tracks`, with page as the default. A client names one with
 * `page[kind]`, or lets a key only one strategy reads (`after`/`before`, `offset`/`limit`)
 * pick it. No `page`, or only the shared `size`, keeps the page strategy, so every
 * request written before the menu existed gets the same response.
 *
 * The seeded catalogue has three non-explicit tracks (1, 3, 4) under the default
 * `explicit=false` filter. Every walk below must visit all three, in id order, once,
 * without leaving the strategy it started on.
 *
 * When a request names its strategy with `page[kind]`, every pagination link must carry
 * it too. Core 1.1.1 drops it (and every other request `page` member) when it builds the
 * links, so the cursor `first` link, which is only `page[size]`, falls back to the page
 * default. The tests that pin the fixed behaviour skip until core 1.2.0 is installed.
 */
#[Group('spec:fetching')]
#[Group('spec:fetching-pagination')]
final class PaginationMenuTest extends MusicCatalogKernelTestCase
{
    private const array ALL_TRACKS = ['1', '3', '4'];

    #[Test]
    public function noPageParameterUsesThePageDefault(): void
    {
        [$document, $contentType] = $this->fetch('/tracks');

        self::assertSame(self::ALL_TRACKS, $this->ids($document));
        self::assertStringNotContainsString(CursorPaginationProfile::URI, $contentType);
        self::assertSame(
            ['currentPage' => 1, 'perPage' => 15, 'from' => 1, 'to' => 3],
            $this->pageMeta($document),
        );
        self::assertSame('/tracks?page[number]=1&page[size]=15', $this->link($document, 'self'));
    }

    #[Test]
    public function pageKindSelectsThePageStrategy(): void
    {
        [$document, $contentType] = $this->fetch('/tracks?page[kind]=page&page[size]=2');

        self::assertSame(['1', '3'], $this->ids($document));
        self::assertStringNotContainsString(CursorPaginationProfile::URI, $contentType);
        self::assertSame(1, $this->pageMeta($document)['currentPage'] ?? null);
        self::assertSame(['number' => '2', 'size' => '2'], $this->pageQuery($this->link($document, 'next'), withoutKind: true));
    }

    #[Test]
    public function cursorKindSelectsTheCursorStrategy(): void
    {
        [$document, $contentType] = $this->fetch('/tracks?page[kind]=cursor&page[size]=2');

        self::assertSame(['1', '3'], $this->ids($document));
        self::assertStringContainsString(CursorPaginationProfile::URI, $contentType);
        self::assertTrue($this->pageMeta($document)['hasMore'] ?? null);
        self::assertSame(['after', 'size'], \array_keys($this->pageQuery($this->link($document, 'next'), withoutKind: true)));
    }

    #[Test]
    public function offsetKindSelectsTheOffsetStrategy(): void
    {
        [$document, $contentType] = $this->fetch('/tracks?page[kind]=offset&page[size]=2');

        // `size` belongs to page and cursor, not offset, so the offset arm ignores it
        // and uses its own default limit.
        self::assertSame(self::ALL_TRACKS, $this->ids($document));
        self::assertStringNotContainsString(CursorPaginationProfile::URI, $contentType);
        self::assertSame(0, $this->pageMeta($document)['offset'] ?? null);
        self::assertSame(['offset' => '0', 'limit' => '15'], $this->pageQuery($this->link($document, 'self'), withoutKind: true));
    }

    #[Test]
    public function aCursorOnlyKeySelectsCursorWithoutAKind(): void
    {
        [$first] = $this->fetch('/tracks?page[kind]=cursor&page[size]=2');
        $after = $this->pageQuery($this->link($first, 'next'))['after'] ?? null;
        self::assertIsString($after);

        [$document, $contentType] = $this->fetch('/tracks?page[after]=' . $after . '&page[size]=2');

        self::assertSame(['4'], $this->ids($document));
        self::assertStringContainsString(CursorPaginationProfile::URI, $contentType);
        self::assertFalse($this->pageMeta($document)['hasMore'] ?? null);
    }

    #[Test]
    public function anOffsetOnlyKeySelectsOffsetWithoutAKind(): void
    {
        [$document] = $this->fetch('/tracks?page[offset]=1&page[limit]=1');

        self::assertSame(['3'], $this->ids($document));
        self::assertSame(['offset' => 1, 'limit' => 1, 'from' => 2, 'to' => 2], $this->pageMeta($document));
    }

    #[Test]
    public function aSharedKeyAloneKeepsThePageDefault(): void
    {
        // `size` is read by page and cursor alike, so it can't pick between them.
        [$document, $contentType] = $this->fetch('/tracks?page[size]=2');

        self::assertSame(['1', '3'], $this->ids($document));
        self::assertStringNotContainsString(CursorPaginationProfile::URI, $contentType);
        self::assertSame(1, $this->pageMeta($document)['currentPage'] ?? null);
    }

    #[Test]
    public function anUnknownKindIsA400(): void
    {
        $response = $this->handle('/tracks?page[kind]=keyset');

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
        $errors = $this->decode($response)['errors'] ?? null;
        self::assertIsArray($errors);
        $error = $errors[0] ?? null;
        self::assertIsArray($error);
        self::assertSame('PAGINATION_KIND_UNKNOWN', $error['code'] ?? null);
        self::assertSame(['parameter' => 'page[kind]'], $error['source'] ?? null);
    }

    #[Test]
    public function thePageLinksWalkTheWholeCollection(): void
    {
        self::assertSame(self::ALL_TRACKS, $this->walk('/tracks?page[kind]=page&page[size]=2', 'next', 'page'));
        self::assertSame(self::ALL_TRACKS, $this->walk('/tracks?page[size]=2', 'next', 'page'));
        self::assertSame(\array_reverse(self::ALL_TRACKS), $this->walk('/tracks?page[number]=2&page[size]=2', 'prev', 'page'));
    }

    #[Test]
    public function theCursorLinksWalkTheWholeCollectionBothWays(): void
    {
        self::assertSame(self::ALL_TRACKS, $this->walk('/tracks?page[kind]=cursor&page[size]=1', 'next', 'cursor'));

        // Walk forward to the last page, then follow `prev` back to the start.
        $path = '/tracks?page[kind]=cursor&page[size]=1';
        for ($i = 0; $i < 2; ++$i) {
            [$document] = $this->fetch($path);
            $path = $this->link($document, 'next');
        }
        self::assertSame(\array_reverse(self::ALL_TRACKS), $this->walk($path, 'prev', 'cursor'));
    }

    #[Test]
    public function theOffsetLinksWalkTheWholeCollection(): void
    {
        self::assertSame(self::ALL_TRACKS, $this->walk('/tracks?page[kind]=offset&page[limit]=2', 'next', 'offset'));
        self::assertSame(\array_reverse(self::ALL_TRACKS), $this->walk('/tracks?page[offset]=2&page[limit]=2', 'prev', 'offset'));
    }

    #[Test]
    public function pageLinksCarryTheNamedKind(): void
    {
        $this->assertEveryLinkCarriesTheKind('page', '/tracks?page[kind]=page&page[number]=2&page[size]=1');
    }

    #[Test]
    public function cursorLinksCarryTheNamedKind(): void
    {
        $this->assertEveryLinkCarriesTheKind('cursor', '/tracks?page[kind]=cursor&page[size]=1');
    }

    #[Test]
    public function offsetLinksCarryTheNamedKind(): void
    {
        $this->assertEveryLinkCarriesTheKind('offset', '/tracks?page[kind]=offset&page[offset]=1&page[limit]=1');
    }

    #[Test]
    public function theCursorFirstLinkStaysOnCursor(): void
    {
        self::requireMenuLinkFix();

        [$first] = $this->fetch('/tracks?page[kind]=cursor&page[size]=1');
        [$second] = $this->fetch($this->link($first, 'next'));

        [$document, $contentType] = $this->fetch($this->link($second, 'first'));

        self::assertSame(['1'], $this->ids($document));
        self::assertStringContainsString(CursorPaginationProfile::URI, $contentType);
        self::assertArrayHasKey('hasMore', $this->pageMeta($document));
    }

    #[Test]
    public function everyStrategyKeepsThePageSizeCap(): void
    {
        foreach (['page[size]=1000000', 'page[kind]=cursor&page[size]=1000000', 'page[limit]=1000000'] as $query) {
            [$document] = $this->fetch('/tracks?' . $query);
            $meta = $this->pageMeta($document);
            self::assertSame(SeedManifest::MAX_PER_PAGE, $meta['perPage'] ?? $meta['limit'] ?? null, $query);
        }
    }

    /**
     * Follows `$rel` links from `$path` until there are none, collecting the ids of each
     * page and checking every page came from `$strategy`. A `prev` walk collects each
     * page reversed, so the result reads last-first.
     *
     * @return list<string>
     */
    private function walk(string $path, string $rel, string $strategy): array
    {
        $ids = [];
        for ($guard = 0; $guard < 10; ++$guard) {
            [$document, $contentType] = $this->fetch($path);
            $this->assertServedBy($strategy, $document, $contentType);
            $pageIds = $this->ids($document);
            \array_push($ids, ...($rel === 'prev' ? \array_reverse($pageIds) : $pageIds));

            $links = $document['links'] ?? null;
            self::assertIsArray($links);
            if (!isset($links[$rel])) {
                return $ids;
            }
            $path = $this->link($document, $rel);
        }

        self::fail("Following '{$rel}' from {$path} did not terminate.");
    }

    private function assertEveryLinkCarriesTheKind(string $kind, string $path): void
    {
        self::requireMenuLinkFix();

        [$document] = $this->fetch($path);
        $links = $document['links'] ?? null;
        self::assertIsArray($links);
        unset($links['describedby']);
        self::assertNotEmpty($links);

        foreach (\array_keys($links) as $rel) {
            self::assertIsString($rel);
            self::assertSame($kind, $this->pageQuery($this->link($document, $rel))['kind'] ?? null, "links.{$rel}");
        }
    }

    /**
     * Checks which strategy rendered a page: only cursor advertises the cursor profile,
     * and page and offset each have their own `meta.page` members.
     *
     * @param array<string, mixed> $document
     */
    private function assertServedBy(string $strategy, array $document, string $contentType): void
    {
        $meta = $this->pageMeta($document);
        if ($strategy === 'cursor') {
            self::assertStringContainsString(CursorPaginationProfile::URI, $contentType);
            self::assertArrayHasKey('hasMore', $meta);

            return;
        }

        self::assertStringNotContainsString(CursorPaginationProfile::URI, $contentType);
        self::assertArrayHasKey($strategy === 'page' ? 'currentPage' : 'offset', $meta);
    }

    /**
     * Core 1.1.1 drops `page[kind]` from a menu's pagination links; 1.2.0 keeps it.
     */
    private static function requireMenuLinkFix(): void
    {
        if (\version_compare(InstalledVersions::getVersion('haddowg/json-api') ?? '0', '1.2.0', '<')) {
            self::markTestSkipped('needs haddowg/json-api >= 1.2.0: menu links keep page[kind]');
        }
    }

    /**
     * The `page` members of a link's query.
     *
     * @return array<string, mixed>
     */
    private function pageQuery(string $link, bool $withoutKind = false): array
    {
        \parse_str((string) \parse_url($link, \PHP_URL_QUERY), $query);
        $page = $query['page'] ?? [];
        self::assertIsArray($page);
        if ($withoutKind) {
            unset($page['kind']);
        }

        /** @var array<string, mixed> $page */
        return $page;
    }

    /**
     * @return array{0: array<string, mixed>, 1: string}
     */
    private function fetch(string $path): array
    {
        $response = $this->handle($path);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $contentType = $response->headers->get('Content-Type');
        self::assertIsString($contentType);
        self::assertStringStartsWith('application/vnd.api+json', $contentType);

        return [$this->decode($response), $contentType];
    }

    /**
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
            self::assertSame('tracks', $resource['type'] ?? null);
            $id = $resource['id'] ?? null;
            self::assertIsString($id);
            $ids[] = $id;
        }

        return $ids;
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private function pageMeta(array $document): array
    {
        $meta = $document['meta'] ?? null;
        self::assertIsArray($meta);
        $page = $meta['page'] ?? null;
        self::assertIsArray($page);

        /** @var array<string, mixed> $page */
        return $page;
    }

    /**
     * The named top-level link as a decoded path + query, ready to re-issue.
     *
     * @param array<string, mixed> $document
     */
    private function link(array $document, string $rel): string
    {
        $links = $document['links'] ?? null;
        self::assertIsArray($links);
        $href = $links[$rel] ?? null;
        self::assertIsString($href, "links.{$rel}");

        $path = (string) \parse_url($href, \PHP_URL_PATH);
        $query = \parse_url($href, \PHP_URL_QUERY);

        return \is_string($query) ? $path . '?' . \urldecode($query) : $path;
    }
}
