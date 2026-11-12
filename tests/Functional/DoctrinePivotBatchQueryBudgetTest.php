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
     * @return list<string>
     */
    private function statements(string $path): array
    {
        $logger = static::getContainer()->get(QueryCountingLogger::class);
        self::assertInstanceOf(QueryCountingLogger::class, $logger);
        $logger->reset();

        $response = $this->handle(self::BASE_URI . $path, extraServer: ['HTTP_ACCEPT' => self::ACCEPT]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return $logger->statements();
    }
}
