<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApi\Pagination\OffsetWindow;
use haddowg\JsonApi\Request\JsonApiRequest;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Schema\Profile\RelationshipQueriesProfile;
use haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineDataProvider;
use haddowg\JsonApiBundle\DataProvider\PivotCollectionResult;
use haddowg\JsonApiBundle\DataProvider\RelationCriteriaFactory;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\DoctrinePlaylistResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\DoctrineTrackResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\PlaylistEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\TrackEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\QueryCountingLogger;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * A page of playlists reads its pivot relation in one batch, and every playlist gets
 * the members, order and `meta.pivot` the single-parent pivot query gives it. The same
 * assertions run with window functions on, off, and with `tracks` scoped by a query
 * extension, so the native window, the per-parent fallback and the scoped read are each
 * held to the single-parent result.
 */
abstract class DoctrinePivotBatchTestCase extends JsonApiFunctionalTestCase
{
    use SeedsPivotBatch;

    protected const string BASE_URI = 'https://example.test';

    protected const string PROFILE_ACCEPT = 'application/vnd.api+json;profile="' . RelationshipQueriesProfile::URI . '"';

    /** The id of the track titled "Hidden". */
    protected const string HIDDEN = '7';

    /**
     * Whether {@see App\Doctrine\HiddenTracksExtension} scopes the track titled "Hidden" out.
     */
    abstract protected function hidesHiddenTrack(): bool;

    #[Test]
    #[Group('spec:fetching-includes')]
    public function eachPlaylistIncludesItsOwnMembersWithItsOwnPivot(): void
    {
        $document = $this->document('/playlists?page[size]=12&include=orderedTracks&fields[playlists]=orderedTracks');

        for ($n = 1; $n <= 11; ++$n) {
            $expected = $this->seededMembers($n);
            \usort($expected, static fn(array $a, array $b): int => (int) $a[0] <=> (int) $b[0]);

            self::assertSame($expected, $this->positions($document, (string) $n, 'orderedTracks'), "playlist {$n}");
        }

        // Playlist 12 holds track 1 three times: it is one member.
        self::assertSame(
            $this->visible(['1', '2', '6', self::HIDDEN]),
            \array_column($this->positions($document, '12', 'orderedTracks'), 0),
        );
    }

    #[Test]
    #[Group('spec:fetching-includes')]
    public function aPivotSortOrdersEachPlaylistsMembers(): void
    {
        $document = $this->document('/playlists?page[size]=11&include=orderedTracks&fields[playlists]=orderedTracks&relatedQuery[orderedTracks][sort]=-position');

        for ($n = 1; $n <= 11; ++$n) {
            $expected = $this->seededMembers($n);
            \usort($expected, static fn(array $a, array $b): int => $b[1] <=> $a[1]);

            self::assertSame($expected, $this->positions($document, (string) $n, 'orderedTracks'), "playlist {$n}");
        }
    }

    #[Test]
    #[Group('spec:fetching-includes')]
    public function aRelatedFilterNarrowsEachPlaylistsMembers(): void
    {
        $document = $this->document('/playlists?page[size]=11&include=tracks&fields[playlists]=tracks&relatedQuery[tracks][filter][title]=ha&relatedQuery[tracks][sort]=position');

        // Only "Alpha" and "Charlie" hold "ha".
        for ($n = 1; $n <= 11; ++$n) {
            $expected = \array_values(\array_filter(
                $this->seededMembers($n),
                static fn(array $member): bool => \in_array($member[0], ['1', '3'], true),
            ));
            \usort($expected, static fn(array $a, array $b): int => $a[1] <=> $b[1]);

            self::assertSame($expected, $this->positions($document, (string) $n, 'tracks'), "playlist {$n}");
        }
    }

    /**
     * The relatedQuery of an include, with `REL` for the relation, and the same query on
     * the related endpoint.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function includeQueries(): iterable
    {
        yield 'unsorted' => ['', ''];
        yield 'pivot sort' => ['&relatedQuery[REL][sort]=-position', '?sort=-position'];
        yield 'related sort' => ['&relatedQuery[REL][sort]=title', '?sort=title'];
        yield 'pivot then related sort' => ['&relatedQuery[REL][sort]=addedAt,-title', '?sort=addedAt,-title'];
        yield 'related filter' => ['&relatedQuery[REL][filter][title]=o&relatedQuery[REL][sort]=position', '?filter[title]=o&sort=position'];
    }

    #[Test]
    #[Group('spec:fetching-includes')]
    #[DataProvider('includeQueries')]
    public function eachPlaylistsIncludeMatchesItsRelatedEndpoint(string $query, string $endpoint): void
    {
        foreach (['orderedTracks', 'tracks'] as $relation) {
            $included = $this->document(\sprintf(
                '/playlists?page[size]=12&include=%s&fields[playlists]=%s%s',
                $relation,
                $relation,
                \str_replace('REL', $relation, $query),
            ));

            for ($n = 1; $n <= 12; ++$n) {
                $related = $this->document(\sprintf('/playlists/%d/%s%s', $n, $relation, $endpoint));
                $data = $related['data'] ?? null;
                self::assertIsArray($data);

                self::assertSame(
                    $this->membersWithPivot($data),
                    $this->membersWithPivot($this->relationship($included, (string) $n, $relation)['data'] ?? null),
                    "playlist {$n} {$relation}{$query}",
                );
            }
        }
    }

    /**
     * @return iterable<string, array{0: string, 1: ?OffsetWindow, 2: bool, 3?: array<string, string>}>
     */
    public static function batchCriteria(): iterable
    {
        yield 'no window' => ['', null, false];
        yield 'no window, pivot sort' => ['-position', null, false];
        yield 'no window, related sort' => ['title', null, false];
        yield 'no window, related filter' => ['position', null, false, ['title' => 'o']];
        yield 'count-free page' => ['position', new OffsetWindow(0, 2), false];
        yield 'counted page' => ['-position', new OffsetWindow(0, 2), true];
        yield 'counted page, unsorted' => ['', new OffsetWindow(0, 3), true];
        yield 'count-free page past the members' => ['title', new OffsetWindow(0, 10), false];
        yield 'counted filtered page' => ['addedAt', new OffsetWindow(0, 1), true, ['title' => 'a']];
        yield 'second page' => ['position', new OffsetWindow(2, 2), true];
        yield 'second count-free page' => ['position', new OffsetWindow(1, 2), false];
    }

    /**
     * @param array<string, string> $filter
     */
    #[Test]
    #[DataProvider('batchCriteria')]
    public function theBatchMatchesTheSingleParentQueryForEveryParent(string $sort, ?OffsetWindow $window, bool $wantsCount, array $filter = []): void
    {
        $provider = $this->provider();
        $relation = $this->relation('orderedTracks');
        $request = new JsonApiRequest(new ServerRequest('GET', '/playlists'));
        $criteria = (new RelationCriteriaFactory())->criteriaFor(
            new QueryParameters(fields: [], includes: [], sort: $sort === '' ? [] : \explode(',', $sort), filter: $filter, pagination: []),
            new DoctrineTrackResource(),
            $relation,
            $window,
            includePivotFields: false,
            wantsCount: $wantsCount,
            request: $request,
            pivotSorts: true,
        );
        $parents = $this->playlists();

        $batch = $provider->fetchRelatedCollectionBatch('playlists', $parents, $relation, $criteria, $request);

        foreach ($parents as $parent) {
            $id = (string) $parent->id;
            $single = $provider->fetchRelatedPivotCollection('tracks', $parent, $relation, $criteria, $request);

            self::assertSame($this->describe($single), $this->describe($batch->for($id)), "playlist {$id}");
        }
    }

    #[Test]
    public function anUnwindowedBatchIsOneQueryWhateverThePageSize(): void
    {
        $provider = $this->provider();
        $relation = $this->relation('orderedTracks');
        $request = new JsonApiRequest(new ServerRequest('GET', '/playlists'));
        $criteria = (new RelationCriteriaFactory())->criteriaFor(
            new QueryParameters(fields: [], includes: [], sort: ['-position'], filter: [], pagination: []),
            new DoctrineTrackResource(),
            $relation,
            null,
            includePivotFields: false,
            request: $request,
            pivotSorts: true,
        );
        $logger = $this->logger();

        foreach ([3, 12] as $size) {
            $parents = \array_slice($this->playlists(), 0, $size);
            $logger->reset();

            $batch = $provider->fetchRelatedCollectionBatch('playlists', $parents, $relation, $criteria, $request);

            self::assertSame(1, $logger->queryCount(), \implode("\n", $logger->statements()));
            self::assertCount($this->hidesHiddenTrack() ? 3 : 4, \iterator_to_array($batch->for((string) $parents[0]->id)->items, false));
        }
    }

    #[Test]
    #[Group('spec:fetching-includes')]
    public function theScopedTrackIsLeftOutOnlyWhereTheExtensionApplies(): void
    {
        $document = $this->document('/playlists?page[size]=12&include=orderedTracks&fields[playlists]=orderedTracks');

        $included = $document['included'] ?? [];
        self::assertIsArray($included);
        $ids = [];
        foreach ($included as $resource) {
            self::assertIsArray($resource);
            $ids[] = $resource['id'] ?? null;
        }

        self::assertSame(!$this->hidesHiddenTrack(), \in_array(self::HIDDEN, $ids, true));
    }

    /**
     * The id and pivot position of playlist `$n`'s members, as seeded.
     *
     * @return list<array{string, int}>
     */
    protected function seededMembers(int $n): array
    {
        $members = [
            [(string) (($n - 1) % 6 + 1), 3],
            [(string) ($n % 6 + 1), 1],
            [(string) (($n + 1) % 6 + 1), 2],
        ];
        if (!$this->hidesHiddenTrack()) {
            $members[] = [self::HIDDEN, 4];
        }

        return $members;
    }

    /**
     * `$ids` without the hidden track when the extension scopes it out.
     *
     * @param list<string> $ids
     *
     * @return list<string>
     */
    protected function visible(array $ids): array
    {
        return \array_values(\array_filter($ids, fn(string $id): bool => !$this->hidesHiddenTrack() || $id !== self::HIDDEN));
    }

    /**
     * @return array<string, mixed>
     */
    protected function document(string $path): array
    {
        $response = $this->handle(self::BASE_URI . $path, extraServer: ['HTTP_ACCEPT' => self::PROFILE_ACCEPT]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return $this->decode($response);
    }

    protected function logger(): QueryCountingLogger
    {
        $logger = static::getContainer()->get(QueryCountingLogger::class);
        self::assertInstanceOf(QueryCountingLogger::class, $logger);

        return $logger;
    }

    /**
     * The linkage of playlist `$id`'s `$name` as member id and pivot position, in order.
     *
     * @param array<string, mixed> $document
     *
     * @return list<array{string, mixed}>
     */
    private function positions(array $document, string $id, string $name): array
    {
        $positions = [];
        foreach ($this->membersWithPivot($this->relationship($document, $id, $name)['data'] ?? null) as [$memberId, $pivot]) {
            $positions[] = [$memberId, $pivot['position'] ?? null];
        }

        return $positions;
    }

    /**
     * Each member's id and `meta.pivot`, in order.
     *
     * @return list<array{string, array<array-key, mixed>}>
     */
    private function membersWithPivot(mixed $data): array
    {
        self::assertIsArray($data);

        $members = [];
        foreach ($data as $member) {
            self::assertIsArray($member);
            $memberId = $member['id'] ?? null;
            self::assertIsString($memberId);
            $meta = $member['meta'] ?? [];
            self::assertIsArray($meta);
            $pivot = $meta['pivot'] ?? [];
            self::assertIsArray($pivot);
            $members[] = [$memberId, $pivot];
        }

        return $members;
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<array-key, mixed>
     */
    private function relationship(array $document, string $id, string $name): array
    {
        $data = $document['data'] ?? null;
        self::assertIsArray($data);
        foreach ($data as $resource) {
            self::assertIsArray($resource);
            if (($resource['id'] ?? null) !== $id) {
                continue;
            }
            $relationships = $resource['relationships'] ?? null;
            self::assertIsArray($relationships);
            $relationship = $relationships[$name] ?? null;
            self::assertIsArray($relationship);

            return $relationship;
        }

        self::fail(\sprintf('The document holds no playlist %s.', $id));
    }

    /**
     * A result's member ids, pivot map and window flags.
     *
     * @param CollectionResult<object> $result
     *
     * @return array<string, mixed>
     */
    private function describe(CollectionResult $result): array
    {
        self::assertInstanceOf(PivotCollectionResult::class, $result);

        $ids = [];
        foreach ($result->items as $item) {
            self::assertInstanceOf(TrackEntity::class, $item);
            $ids[] = $item->id;
        }

        return [
            'ids' => $ids,
            'pivot' => \array_map(
                static fn(array $values): array => \array_map(
                    static fn(mixed $value): mixed => $value instanceof \DateTimeInterface ? $value->format(\DATE_ATOM) : $value,
                    $values,
                ),
                $result->pivotMap,
            ),
            'total' => $result->total,
            'windowed' => $result->windowed,
            'hasMore' => $result->hasMore,
        ];
    }

    private function provider(): DoctrineDataProvider
    {
        $provider = static::getContainer()->get(DoctrineDataProvider::class);
        self::assertInstanceOf(DoctrineDataProvider::class, $provider);

        return $provider;
    }

    private function relation(string $name): RelationInterface
    {
        foreach ((new DoctrinePlaylistResource())->allFields() as $field) {
            if ($field instanceof RelationInterface && $field->name() === $name) {
                return $field;
            }
        }

        self::fail(\sprintf('The playlists resource declares no relation "%s".', $name));
    }

    /**
     * @return list<PlaylistEntity>
     */
    private function playlists(): array
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        return \array_values($entityManager->getRepository(PlaylistEntity::class)->findBy([], ['id' => 'ASC']));
    }
}
