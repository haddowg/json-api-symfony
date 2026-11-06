<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApi\OpenApi\GeneratorContract;
use haddowg\JsonApiBundle\OpenApi\DocumentFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * Sort families on both data layers, over the six campaigns of
 * {@see \haddowg\JsonApiBundle\Tests\Functional\App\SortFamily\CampaignFixtures}.
 *
 * `value.cap` resolves to a custom sort over the campaign's `cap` placeholder: an arm
 * reading it in memory, and on Doctrine an arm joining the value table and casting
 * its text to an integer. `field.name` resolves to a plain field sort. Caps are
 * 1:30, 2:5, 3:none, 4:120, 5:5, 6:none, and names run Alpha 3, Bravo 5, Charlie 4,
 * Delta 1, Echo 6, Foxtrot 2.
 *
 * Offset pages order a missing cap first ascending, as both stores do. Cursor pages
 * order it as the largest value, the keyset's forced order.
 */
abstract class SortFamilyConformanceTestCase extends JsonApiFunctionalTestCase
{
    /**
     * The cursor order of each sort: missing caps sort last ascending and first
     * descending, and the id tiebreak follows the last key's direction.
     */
    private const array CURSOR_ORDERS = [
        'value.cap' => ['2', '5', '1', '4', '3', '6'],
        '-value.cap' => ['6', '3', '4', '1', '5', '2'],
        '-value.cap,name' => ['3', '6', '4', '1', '5', '2'],
        'name,value.cap' => ['3', '5', '4', '1', '6', '2'],
        '-field.name' => ['2', '6', '1', '4', '5', '3'],
    ];

    #[Test]
    #[Group('spec:fetching-sorting')]
    public function aFamilyKeyResolvingToAFieldSortOrdersByThatField(): void
    {
        self::assertSame(['3', '5', '4', '1', '6', '2'], $this->ids('/campaigns?sort=field.name&page[number]=1&page[size]=10'));
        self::assertSame(['2', '6', '1', '4', '5', '3'], $this->ids('/campaigns?sort=-field.name&page[number]=1&page[size]=10'));
    }

    #[Test]
    #[Group('spec:fetching-sorting')]
    public function aFamilyKeyResolvingToACustomSortOrdersNumerically(): void
    {
        self::assertSame(['3', '6', '2', '5', '1', '4'], $this->ids('/campaigns?sort=value.cap,id&page[number]=1&page[size]=10'));
        self::assertSame(['4', '1', '2', '5', '3', '6'], $this->ids('/campaigns?sort=-value.cap,id&page[number]=1&page[size]=10'));
    }

    #[Test]
    #[Group('spec:fetching-sorting')]
    public function aFamilyKeyMixesWithAFixedKey(): void
    {
        // Bravo before Foxtrot breaks the tie on cap 5.
        self::assertSame(['4', '1', '5', '2', '3', '6'], $this->ids('/campaigns?sort=-value.cap,name&page[number]=1&page[size]=10'));
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function aFamilySortedCollectionPagesByNumber(): void
    {
        self::assertSame(['3', '6'], $this->ids('/campaigns?sort=value.cap,id&page[number]=1&page[size]=2'));
        self::assertSame(['2', '5'], $this->ids('/campaigns?sort=value.cap,id&page[number]=2&page[size]=2'));
        self::assertSame(['1', '4'], $this->ids('/campaigns?sort=value.cap,id&page[number]=3&page[size]=2'));
    }

    #[Test]
    #[Group('spec:fetching-sorting')]
    #[Group('spec:errors')]
    public function aKeyNoFamilyResolvesIsA400(): void
    {
        // An unknown suffix, a suffix outside the pattern, the bare prefix either way
        // round, and a suffix the field family rejects; on offset and cursor pages.
        foreach (['value.budget', 'value.Cap', 'value', '-value', 'field.id'] as $sort) {
            foreach (['page[number]=1', 'page[size]=2'] as $page) {
                $response = $this->handle('/campaigns?sort=' . $sort . '&' . $page);

                self::assertSame(400, $response->getStatusCode(), $sort . ': ' . $response->getContent());
                $errors = $this->decode($response)['errors'] ?? null;
                self::assertIsArray($errors);
                self::assertIsArray($errors[0] ?? null);
                self::assertSame('SORTING_UNRECOGNIZED', $errors[0]['code'] ?? null, $sort);
            }
        }
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    #[Group('spec:fetching-sorting')]
    public function cursorPagesWalkAFamilySortForwards(): void
    {
        foreach (self::CURSOR_ORDERS as $sort => $expected) {
            foreach ([1, 2, 4] as $size) {
                self::assertSame($expected, \array_merge(...\array_column($this->forwardPages($sort, $size), 'ids')), $sort . ', page size ' . $size);
            }
        }
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    #[Group('spec:fetching-sorting')]
    public function cursorPagesWalkAFamilySortBackwards(): void
    {
        foreach (self::CURSOR_ORDERS as $sort => $expected) {
            foreach ([1, 2, 4] as $size) {
                $this->assertBackwardPagesMatchForward($sort, $size);
            }
        }
    }

    #[Test]
    #[Group('spec:openapi')]
    public function theOpenApiDocumentAdvertisesTheFamilies(): void
    {
        $factory = static::getContainer()->get(DocumentFactory::class);
        self::assertInstanceOf(DocumentFactory::class, $factory);
        $document = $factory->forServer()->toArray();

        $info = $document['info'] ?? null;
        self::assertIsArray($info);
        self::assertSame(['contract' => \max(GeneratorContract::BASELINE, GeneratorContract::SORT_FAMILIES)], $info['x-generator'] ?? null);

        $sort = null;
        foreach ($this->at($document, 'paths', '/campaigns', 'get', 'parameters') as $parameter) {
            if (\is_array($parameter) && ($parameter['name'] ?? null) === 'sort') {
                $sort = $parameter['schema'] ?? null;
            }
        }
        self::assertIsArray($sort);
        self::assertSame(
            [['prefix' => 'value', 'suffixPattern' => '[a-z_]+'], ['prefix' => 'field', 'suffixPattern' => '[^,]+']],
            $sort['x-sort-families'] ?? null,
        );
        self::assertSame(
            [
                ['type' => 'string', 'enum' => ['id', '-id', 'name', '-name']],
                ['type' => 'string', 'pattern' => '^-?value\\.(?:[a-z_]+)$'],
                ['type' => 'string', 'pattern' => '^-?field\\.(?:[^,]+)$'],
            ],
            $this->at($sort, 'items', 'anyOf'),
        );
    }

    #[Test]
    #[Group('spec:fetching-pagination')]
    public function aCursorMintedUnderOneFamilyKeyIsStaleUnderAnother(): void
    {
        [, $links] = $this->page('/campaigns?sort=value.cap&page[size]=2');
        self::assertArrayHasKey('next', $links);
        $next = $this->relativePath($links['next']);

        $response = $this->handle(\str_replace('sort=value.cap', 'sort=field.name', $next));

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
    }

    /**
     * @return list<string>
     */
    private function ids(string $path): array
    {
        $response = $this->handle($path);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return $this->idsOf($this->decode($response));
    }

    private function assertBackwardPagesMatchForward(string $sort, int $size): void
    {
        $forward = $this->forwardPages($sort, $size);

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

        self::assertSame(\array_column($forward, 'ids'), \array_reverse($backward), $sort . ', page size ' . $size);
    }

    /**
     * @return list<array{ids: list<string>, path: string}>
     */
    private function forwardPages(string $sort, int $size): array
    {
        $path = '/campaigns?sort=' . $sort . '&page[size]=' . $size;

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
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $document = $this->decode($response);

        $links = [];
        foreach (\is_array($document['links'] ?? null) ? $document['links'] : [] as $name => $link) {
            $href = \is_array($link) ? ($link['href'] ?? null) : $link;
            if (\is_string($href)) {
                $links[(string) $name] = $href;
            }
        }

        return [$this->idsOf($document), $links];
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return list<string>
     */
    private function idsOf(array $document): array
    {
        $data = $document['data'] ?? null;
        self::assertIsArray($data);

        $ids = [];
        foreach ($data as $resource) {
            self::assertIsArray($resource);
            self::assertSame('campaigns', $resource['type'] ?? null);
            self::assertIsString($resource['id'] ?? null);
            $ids[] = $resource['id'];
        }

        return $ids;
    }

    /**
     * @param array<mixed> $document
     *
     * @return array<mixed>
     */
    private function at(array $document, string ...$path): array
    {
        foreach ($path as $key) {
            $document = $document[$key] ?? null;
            self::assertIsArray($document, $key);
        }

        return $document;
    }

    private function relativePath(string $url): string
    {
        $path = (string) \parse_url($url, \PHP_URL_PATH);
        $query = \parse_url($url, \PHP_URL_QUERY);

        return \is_string($query) ? $path . '?' . $query : $path;
    }
}
