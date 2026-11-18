<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * Cursor pages ordered by custom sorts over the six teams of
 * {@see \haddowg\JsonApiBundle\Tests\Functional\App\KeysetDql\TeamFixtures}. On Doctrine
 * the sorts are a subquery whose value the cursor reads from the query, a bare `CASE`
 * and a bare `COALESCE`; in memory they are each sort's `keysetValue()`.
 *
 * Top scores run 1:9, 2:none, 3:7, 4:9, 5:none, 6:2. Tiers run 1:1, 2:2, 3:none,
 * 4:none, 5:2, 6:1. Points run 1:5, 2:4, 3:none, 4:8, 5:10, 6:1. A missing value sorts
 * last ascending and first descending, and the id tiebreak follows the last key.
 */
abstract class KeysetDqlConformanceTestCase extends JsonApiFunctionalTestCase
{
    private const array ORDERS = [
        'topScore' => ['6', '3', '1', '4', '2', '5'],
        '-topScore' => ['5', '2', '4', '1', '3', '6'],
        'tier' => ['1', '6', '2', '5', '3', '4'],
        '-tier' => ['4', '3', '5', '2', '6', '1'],
        'points' => ['6', '2', '1', '4', '5', '3'],
        '-points' => ['3', '5', '4', '1', '2', '6'],
        'tier,-topScore' => ['1', '6', '5', '2', '4', '3'],
        '-topScore,tier' => ['2', '5', '1', '4', '3', '6'],
    ];

    /**
     * The collections holding all six teams. On Doctrine a league's teams page through
     * their association entity, the pivot query.
     *
     * @return list<string>
     */
    protected function collectionPaths(): array
    {
        return ['/teams'];
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    #[Group('spec:fetching-sorting')]
    public function theFirstTwoPagesFollowTheSort(): void
    {
        foreach ($this->collectionPaths() as $collection) {
            foreach (self::ORDERS as $sort => $expected) {
                $pages = $this->forwardPages($collection, $sort, 2);

                self::assertSame(\array_slice($expected, 0, 2), $pages[0]['ids'], $collection . ' ' . $sort . ', page 1');
                self::assertSame(\array_slice($expected, 2, 2), $pages[1]['ids'] ?? null, $collection . ' ' . $sort . ', page 2');
            }
        }
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    #[Group('spec:fetching-sorting')]
    public function cursorPagesWalkTheSortForwards(): void
    {
        foreach ($this->collectionPaths() as $collection) {
            foreach (self::ORDERS as $sort => $expected) {
                foreach ([1, 2, 4] as $size) {
                    self::assertSame($expected, \array_merge(...\array_column($this->forwardPages($collection, $sort, $size), 'ids')), $collection . ' ' . $sort . ', page size ' . $size);
                }
            }
        }
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    #[Group('spec:fetching-sorting')]
    public function cursorPagesWalkTheSortBackwards(): void
    {
        foreach ($this->collectionPaths() as $collection) {
            foreach (\array_keys(self::ORDERS) as $sort) {
                foreach ([1, 2, 4] as $size) {
                    $this->assertBackwardPagesMatchForward($collection, $sort, $size);
                }
            }
        }
    }

    private function assertBackwardPagesMatchForward(string $collection, string $sort, int $size): void
    {
        $forward = $this->forwardPages($collection, $sort, $size);

        $backward = [];
        $path = $forward[\count($forward) - 1]['path'];
        while (true) {
            [$ids, $links] = $this->page($path);
            $backward[] = $ids;
            if (!isset($links['prev'])) {
                break;
            }
            $path = $this->relativePath($links['prev']);
            self::assertLessThan(10, \count($backward), 'backward paging must terminate');
        }

        self::assertSame(\array_column($forward, 'ids'), \array_reverse($backward), $collection . ' ' . $sort . ', page size ' . $size);
    }

    /**
     * @return list<array{ids: list<string>, path: string}>
     */
    private function forwardPages(string $collection, string $sort, int $size): array
    {
        $path = $collection . '?sort=' . $sort . '&page[size]=' . $size;

        $pages = [];
        while (true) {
            [$ids, $links] = $this->page($path);
            $pages[] = ['ids' => $ids, 'path' => $path];
            if (!isset($links['next'])) {
                break;
            }
            $path = $this->relativePath($links['next']);
            self::assertLessThan(10, \count($pages), 'forward paging must terminate');
        }

        return $pages;
    }

    /**
     * @return array{0: list<string>, 1: array<string, string>}
     */
    private function page(string $path): array
    {
        $response = $this->handle($path);
        self::assertSame(200, $response->getStatusCode(), $path . ': ' . $response->getContent());
        $document = $this->decode($response);

        $links = [];
        foreach (\is_array($document['links'] ?? null) ? $document['links'] : [] as $name => $link) {
            $href = \is_array($link) ? ($link['href'] ?? null) : $link;
            if (\is_string($href)) {
                $links[(string) $name] = $href;
            }
        }

        $data = $document['data'] ?? null;
        self::assertIsArray($data);
        $ids = [];
        foreach ($data as $resource) {
            self::assertIsArray($resource);
            self::assertIsString($resource['id'] ?? null);
            $ids[] = $resource['id'];
        }

        return [$ids, $links];
    }

    private function relativePath(string $url): string
    {
        $path = (string) \parse_url($url, \PHP_URL_PATH);
        $query = \parse_url($url, \PHP_URL_QUERY);

        return \is_string($query) ? $path . '?' . $query : $path;
    }
}
