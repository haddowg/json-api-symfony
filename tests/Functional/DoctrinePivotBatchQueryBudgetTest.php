<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApi\Schema\Profile\CountableProfile;
use haddowg\JsonApi\Schema\Profile\RelationshipQueriesProfile;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\PivotBatchOnKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\QueryCountingLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * A page of playlists reads a pivot relation in a fixed number of queries, whatever the
 * page size: one windowed read per rendered pivot relation, never one per playlist.
 */
final class DoctrinePivotBatchQueryBudgetTest extends JsonApiFunctionalTestCase
{
    use SeedsPivotBatch;

    private const string BASE_URI = 'https://example.test';

    private const string ACCEPT = 'application/vnd.api+json;profile="' . RelationshipQueriesProfile::URI . ' ' . CountableProfile::URI . '"';

    protected static function getKernelClass(): string
    {
        return PivotBatchOnKernel::class;
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function pivotReads(): iterable
    {
        yield 'include' => ['include=orderedTracks&fields[playlists]=orderedTracks'];
        yield 'include with a pivot sort' => ['include=orderedTracks&fields[playlists]=orderedTracks&relatedQuery[orderedTracks][sort]=-position'];
        yield 'counted include with a related filter' => ['include=tracks&fields[playlists]=tracks&withCount=tracks&relatedQuery[tracks][filter][title]=a'];
        yield 'withData linkage' => ['fields[playlists]=dataTracks'];
    }

    #[Test]
    #[Group('spec:fetching-includes')]
    #[DataProvider('pivotReads')]
    public function aPageOfPlaylistsReadsItsPivotRelationsInAFixedNumberOfQueries(string $query): void
    {
        $small = $this->statements('/playlists?page[size]=3&' . $query);
        $large = $this->statements('/playlists?page[size]=12&' . $query);

        self::assertCount(\count($small), $large, \sprintf(
            "3 playlists ran %d queries, 12 ran %d:\n%s",
            \count($small),
            \count($large),
            \implode("\n", $large),
        ));

        $perParent = \array_filter($large, static fn(string $sql): bool => \preg_match('/playlist_id = \?/', $sql) === 1);
        self::assertSame([], \array_values($perParent), 'no pivot read is scoped to one playlist');

        $windows = \array_filter($large, static fn(string $sql): bool => \stripos($sql, 'ROW_NUMBER() OVER (PARTITION BY') !== false);
        self::assertNotEmpty($windows, \implode("\n", $large));
        foreach ($windows as $sql) {
            self::assertStringContainsString('playlist_track', $sql);
            self::assertMatchesRegularExpression('/jsonapi_rn\s*<=\s*\?/', $sql);
        }
    }

    #[Test]
    #[Group('spec:fetching-includes')]
    public function aCountedIncludeTakesItsTotalFromTheSameWindow(): void
    {
        $sql = $this->statements('/playlists?page[size]=12&include=tracks&fields[playlists]=tracks&withCount=tracks');

        $counted = \array_filter($sql, static fn(string $s): bool => \str_contains($s, 'COUNT(*) OVER (PARTITION BY'));
        self::assertCount(1, $counted, \implode("\n", $sql));
        self::assertSame([], \array_values(\array_filter(
            $sql,
            static fn(string $s): bool => \stripos($s, 'count(') !== false && \preg_match('/playlist_id = \?/', $s) === 1,
        )), 'no COUNT runs for one playlist');
    }

    /**
     * @return iterable<string, array{string, string, int|null}>
     */
    public static function pivotPageIncludes(): iterable
    {
        yield 'include' => ['include=orderedTracks&fields[playlists]=orderedTracks', 'application/vnd.api+json', 1];
        // The profile windows every relation whose data renders, each in its own query.
        yield 'include under the profile' => ['include=orderedTracks&fields[playlists]=orderedTracks', self::ACCEPT, null];
        yield 'include with a pivot sort' => ['include=orderedTracks&fields[playlists]=orderedTracks&relatedQuery[orderedTracks][sort]=-position', self::ACCEPT, null];
    }

    #[Test]
    #[Group('spec:fetching-includes')]
    #[DataProvider('pivotPageIncludes')]
    public function anIncludedPivotPageRendersThePivotValuesItsWindowRead(string $query, string $accept, ?int $pivotQueries): void
    {
        $statements = $this->statements('/playlists?page[size]=12&' . $query, $accept, $document);

        $pivotReads = \array_values(\array_filter($statements, static fn(string $sql): bool => \str_contains($sql, 'playlist_track')));
        if ($pivotQueries !== null) {
            self::assertCount($pivotQueries, $pivotReads, \implode("\n", $statements));
        }
        foreach ($pivotReads as $sql) {
            self::assertStringContainsStringIgnoringCase('ROW_NUMBER() OVER (PARTITION BY', $sql, 'the pivot values are read with the window only');
        }

        // Each member's pivot is the one its relationship endpoint renders.
        $data = $document['data'] ?? null;
        self::assertIsArray($data);
        self::assertCount(12, $data);
        $members = 0;
        foreach ($data as $playlist) {
            self::assertIsArray($playlist);
            $id = $playlist['id'] ?? null;
            self::assertIsString($id);
            $relationships = $playlist['relationships'] ?? null;
            self::assertIsArray($relationships);
            $relationship = $relationships['orderedTracks'] ?? null;
            self::assertIsArray($relationship);
            $linkage = $relationship['data'] ?? null;
            self::assertIsArray($linkage);

            $this->statements('/playlists/' . $id . '/relationships/orderedTracks', 'application/vnd.api+json', $endpoint);
            $expected = [];
            foreach (\is_array($endpoint['data'] ?? null) ? $endpoint['data'] : [] as $identifier) {
                [$memberId, $pivot] = $this->pivotOf($identifier);
                $expected[$memberId] = $pivot;
            }

            foreach ($linkage as $identifier) {
                [$memberId, $pivot] = $this->pivotOf($identifier);
                self::assertArrayHasKey($memberId, $expected);
                self::assertSame($expected[$memberId], $pivot, "playlist {$id}");
                ++$members;
            }
        }
        self::assertGreaterThan(0, $members);
    }

    /**
     * An identifier's id and `meta.pivot`.
     *
     * @return array{string, mixed}
     */
    private function pivotOf(mixed $identifier): array
    {
        self::assertIsArray($identifier);
        $id = $identifier['id'] ?? null;
        self::assertIsString($id);
        $meta = $identifier['meta'] ?? null;
        self::assertIsArray($meta);

        return [$id, $meta['pivot'] ?? null];
    }

    /**
     * @param array<array-key, mixed>|null $document set to the decoded response
     *
     * @return list<string>
     */
    private function statements(string $path, string $accept = self::ACCEPT, ?array &$document = null): array
    {
        $logger = static::getContainer()->get(QueryCountingLogger::class);
        self::assertInstanceOf(QueryCountingLogger::class, $logger);
        $logger->reset();

        $response = $this->handle(self::BASE_URI . $path, extraServer: ['HTTP_ACCEPT' => $accept]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $decoded = \json_decode((string) $response->getContent(), true);
        $document = \is_array($decoded) ? $decoded : null;

        return $logger->statements();
    }
}
