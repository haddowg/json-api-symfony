<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\PivotRelationInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\Filter\RelationHop;
use haddowg\JsonApi\Resource\Filter\ThroughPathResolver;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApi\Resource\Sort\SortDirective;
use haddowg\JsonApi\Resource\Through\PivotRowRelations;
use haddowg\JsonApi\Schema\Profile\RelationshipQueriesProfile;
use haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineSortHandler;
use haddowg\JsonApiBundle\DataProvider\FilterPaths;
use haddowg\JsonApiBundle\Tests\Functional\App\QueryCountingLogger;
use haddowg\JsonApiBundle\Tests\Functional\App\ThroughPivot\ThroughPivotTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\ThroughPivot\TpAlbumEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\ThroughPivot\TpAuditorEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\ThroughPivot\TpEntryEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\ThroughPivot\TpErrorLogger;
use haddowg\JsonApiBundle\Tests\Functional\App\ThroughPivot\TpPlaylistEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\ThroughPivot\TpPlaylistResource;
use haddowg\JsonApiBundle\Tests\Functional\App\ThroughPivot\TpTrackEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\ThroughPivot\TpTrackResource;
use haddowg\JsonApiBundle\Tests\Functional\App\ThroughPivot\TpUserEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\ThroughPivot\TpUserResource;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * `pivot` segments in `->through()` paths on the Doctrine provider: a leading `pivot` on
 * a relation's related and relationship endpoints and in `relatedQuery`, a pivot field
 * leaf, a relation the pivot row holds, a `pivot` mid-path, the related types' scopes and
 * read gates, the sorts, the warm-up refusals, and the deprecated `pivot.`-prefixed
 * traversals beside their migrated forms. Doctrine-only, since the in-memory provider
 * holds no pivot rows; it refuses these filters with a `400`.
 *
 * Track 1 is on playlist 1, added by sam and audited, and playlist 2, added by kim. Track
 * 2 is on playlist 1, added by a hidden user. Track 3 is on playlist 3, added by kim.
 * Tracks 1 and 2 are on album 1 and track 3 on album 2.
 */
final class DoctrineThroughPivotTest extends JsonApiFunctionalTestCase
{
    private const string QUERIES_ACCEPT = 'application/vnd.api+json;profile="' . RelationshipQueriesProfile::URI . '"';

    protected static function getKernelClass(): string
    {
        return ThroughPivotTestKernel::class;
    }

    protected function tearDown(): void
    {
        ThroughPivotTestKernel::$broken = null;
        parent::tearDown();
    }

    protected function afterBoot(): void
    {
        if (ThroughPivotTestKernel::$broken !== null) {
            return;
        }

        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        $sam = new TpUserEntity(1, 'sam');
        $kim = new TpUserEntity(2, 'kim');
        $ghost = new TpUserEntity(3, 'ghost', hidden: true);
        $audrey = new TpAuditorEntity(1, 'audrey');
        [$morning, $evening, $night] = [new TpPlaylistEntity(1, 'Morning'), new TpPlaylistEntity(2, 'Evening'), new TpPlaylistEntity(3, 'Night')];
        $alpha = new TpAlbumEntity(1, 'Alpha');
        $beta = new TpAlbumEntity(2, 'Beta');
        $one = new TpTrackEntity(1, 'One', $alpha);
        $two = new TpTrackEntity(2, 'Two', $alpha);
        $three = new TpTrackEntity(3, 'Three', $beta);
        $entries = [
            new TpEntryEntity(1, $morning, $one, 1, new \DateTimeImmutable('2026-01-10T00:00:00Z'), $sam, $audrey),
            new TpEntryEntity(2, $evening, $one, 2, new \DateTimeImmutable('2026-03-10T00:00:00Z'), $kim),
            new TpEntryEntity(3, $morning, $two, 2, new \DateTimeImmutable('2026-02-10T00:00:00Z'), $ghost),
            new TpEntryEntity(4, $night, $three, 1, new \DateTimeImmutable('2026-04-10T00:00:00Z'), $kim),
        ];
        foreach ([$sam, $kim, $ghost, $audrey, $morning, $evening, $night, $alpha, $beta, $one, $two, $three, ...$entries] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();
        $entityManager->clear();
    }

    #[Test]
    public function aLeadingPivotFiltersTheRelatedEndpointByARelationThePivotRowHolds(): void
    {
        self::assertSame(['1'], $this->ids('/tpTracks/1/playlists?filter[addedBy]=sam'));
        self::assertSame(['2'], $this->ids('/tpTracks/1/playlists?filter[addedBy]=kim'));
        self::assertSame(['2'], $this->ids('/tpTracks/1/playlists?filter[adder]=2'));
        self::assertSame(['1', '2'], $this->ids('/tpTracks/1/playlists?filter[adder][]=1&filter[adder][]=2'));
    }

    #[Test]
    public function aLeadingPivotFiltersTheRelationshipEndpoint(): void
    {
        self::assertSame(['2'], $this->ids('/tpTracks/1/relationships/playlists?filter[addedBy]=kim'));
        self::assertSame(['1'], $this->ids('/tpTracks/1/relationships/playlists?filter[position]=1'));
    }

    #[Test]
    public function aPivotFieldLeafReadsTheStoredPivotMember(): void
    {
        self::assertSame(['2'], $this->ids('/tpTracks/1/playlists?filter[addedAt][min]=2026-02-01T00:00:00Z'));
        self::assertSame(['1'], $this->ids('/tpTracks/1/playlists?filter[addedAt][max]=2026-02-01T00:00:00Z'));
        self::assertSame(['1'], $this->ids('/tpTracks/1/playlists?filter[position]=1'));
    }

    #[Test]
    public function aLeadingPivotWorksInARelatedQuery(): void
    {
        $document = $this->document('/tpTracks/1?include=playlists&relatedQuery[playlists][filter][addedBy]=kim', ['HTTP_ACCEPT' => self::QUERIES_ACCEPT]);
        self::assertSame(['2'], $this->linkage($document, 'playlists'));

        $sorted = $this->document('/tpTracks/1?include=playlists&relatedQuery[playlists][sort]=-added', ['HTTP_ACCEPT' => self::QUERIES_ACCEPT]);
        self::assertSame(['2', '1'], $this->linkage($sorted, 'playlists'));

        $byAdder = $this->document('/tpTracks/1?include=playlists&relatedQuery[playlists][sort]=adder', ['HTTP_ACCEPT' => self::QUERIES_ACCEPT]);
        self::assertSame(['2', '1'], $this->linkage($byAdder, 'playlists'));
    }

    #[Test]
    public function aSortReadsAPivotFieldOrARelationThePivotRowHolds(): void
    {
        self::assertSame(['2', '1'], $this->ids('/tpTracks/1/playlists?sort=-added'));
        self::assertSame(['1', '2'], $this->ids('/tpTracks/1/playlists?sort=added'));
        self::assertSame(['2', '1'], $this->ids('/tpTracks/1/playlists?sort=adder'));
        self::assertSame(['1', '2'], $this->ids('/tpTracks/1/relationships/playlists?sort=-adder'));
    }

    #[Test]
    public function aCursorPageSortsByALeadingPivotAndFollowsItsNextLink(): void
    {
        foreach (['/tpTracks/1/cursorPlaylists', '/tpTracks/1/relationships/cursorPlaylists'] as $base) {
            foreach (['' => ['2', '1'], '?sort=-added' => ['2', '1'], '?sort=added' => ['1', '2'], '?sort=adder' => ['2', '1'], '?sort=-adder' => ['1', '2']] as $query => $expected) {
                self::assertSame($expected, $this->cursorPages($base . $query), $base . $query);
            }
        }
    }

    #[Test]
    public function anIncludedCursorRelationReadsItsFirstPageInOneWindowPerParent(): void
    {
        $default = $this->document('/tpTracks?include=cursorPlaylists');
        self::assertSame(['1' => ['2'], '2' => ['1'], '3' => ['3']], $this->linkageByParent($default, 'cursorPlaylists'));

        // The first page of every parent is one windowed query over the pivot rows, the
        // only statement joining the users the sort reads.
        $logger = static::getContainer()->get(QueryCountingLogger::class);
        \assert($logger instanceof QueryCountingLogger);
        $logger->reset();
        $this->document('/tpTracks?include=cursorPlaylists&relatedQuery[cursorPlaylists][sort]=adder', ['HTTP_ACCEPT' => self::QUERIES_ACCEPT]);
        $windowed = \array_values(\array_filter($logger->statements(), static fn(string $sql): bool => \str_contains($sql, 'tp_user')));
        self::assertCount(1, $windowed, \implode("\n", $windowed));
        self::assertStringContainsString('ROW_NUMBER', $windowed[0]);

        foreach (['-added' => ['1' => ['2'], '2' => ['1'], '3' => ['3']], 'added' => ['1' => ['1'], '2' => ['1'], '3' => ['3']], 'adder' => ['1' => ['2'], '2' => ['1'], '3' => ['3']], '-adder' => ['1' => ['1'], '2' => ['1'], '3' => ['3']]] as $sort => $expected) {
            $document = $this->document('/tpTracks?include=cursorPlaylists&relatedQuery[cursorPlaylists][sort]=' . $sort, ['HTTP_ACCEPT' => self::QUERIES_ACCEPT]);
            self::assertSame($expected, $this->linkageByParent($document, 'cursorPlaylists'), $sort);
        }
    }

    #[Test]
    public function aPivotMidPathFiltersThroughTheRowsOfEachHop(): void
    {
        self::assertSame(['1', '2'], $this->ids('/tpAlbums?filter[adder]=kim'));
        self::assertSame(['1'], $this->ids('/tpAlbums?filter[adder]=sam'));
        self::assertSame(['2'], $this->ids('/tpAlbums?filter[addedAt][min]=2026-04-01T00:00:00Z'));
        self::assertSame(['1'], $this->ids('/tpAlbums?filter[addedAt][max]=2026-02-15T00:00:00Z'));
    }

    #[Test]
    public function aPivotOnTheFirstRelationOfACollectionGoesOnPastThePivotRow(): void
    {
        self::assertSame(['1', '3'], $this->ids('/tpTracks?filter[playlistAdder]=kim'));
        self::assertSame(['1'], $this->ids('/tpTracks?filter[playlistAdder]=sam'));
        self::assertSame([], $this->ids('/tpTracks?filter[playlistAdder]=ghost'));
    }

    #[Test]
    public function aLeadingPivotSortUnderARelationTheCallerMayNotReadOrdersAsNull(): void
    {
        $relation = null;
        foreach ((new TpTrackResource())->allFields() as $field) {
            if ($field instanceof BelongsToMany && $field->name() === 'playlists') {
                $relation = $field;
            }
        }
        self::assertInstanceOf(BelongsToMany::class, $relation);
        $users = (new TpUserResource())->allFields();
        $rows = new class implements PivotRowRelations {
            private ?RelationInterface $addedBy = null;

            public function pivotRowRelation(PivotRelationInterface $relation, string $parentType, string $member): ?RelationInterface
            {
                return $member === 'addedBy' ? $this->addedBy ??= BelongsTo::make('addedBy', 'tpUsers')->build() : null;
            }
        };
        $resolver = new ThroughPathResolver(static fn(string $type): ?array => $type === 'tpUsers' ? $users : null, $rows);
        // The paths of a default order whose relation the caller may not read.
        $paths = new FilterPaths($resolver, (new TpPlaylistResource())->allFields(), type: 'tpPlaylists', refused: [$relation], under: new RelationHop($relation, 'tpTracks', 'playlists'));
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        foreach (['added' => SortByField::make('added', 'addedAt')->through('pivot'), 'adder' => SortByField::make('adder', 'name')->through('pivot.addedBy')] as $key => $sort) {
            $query = $entityManager->createQueryBuilder()
                ->select('resource')
                ->from(TpPlaylistEntity::class, 'resource')
                ->innerJoin(TpEntryEntity::class, 'pivot', 'WITH', 'pivot.playlist = resource')
                ->where('IDENTITY(pivot.track) = 1');
            $handler = new DoctrineSortHandler();
            $handler->withPaths($paths, static fn(): mixed => $handler->applyOn([new SortDirective($paths->resolveSort($sort), descending: true)], $query, 'resource'));

            self::assertStringContainsString('1 = 0', $query->getDQL(), $key);
            /** @var list<TpPlaylistEntity> $playlists */
            $playlists = $query->getQuery()->getResult();
            $ids = \array_map(static fn(TpPlaylistEntity $playlist): int => $playlist->id, $playlists);
            \sort($ids);
            self::assertSame([1, 2], $ids, $key);
        }
    }

    #[Test]
    public function aRowTheReachedTypesScopeHidesNeverMatches(): void
    {
        self::assertSame([], $this->ids('/tpTracks/2/playlists?filter[addedBy]=ghost'));
        self::assertSame([], $this->ids('/tpAlbums?filter[adder]=ghost'));
    }

    #[Test]
    public function aHopTheCallerMayNotReadIsRefused(): void
    {
        foreach (['/tpTracks/1/playlists?filter[auditedBy]=audrey', '/tpTracks/1/playlists?filter[unaudited]', '/tpAlbums?filter[auditor]=audrey'] as $path) {
            $response = $this->send('ada', $path);
            self::assertSame(403, $response->getStatusCode(), $path . ': ' . $response->getContent());
        }

        self::assertSame(['1'], $this->ids('/tpTracks/1/playlists?filter[auditedBy]=audrey'));
        self::assertSame(['2'], $this->ids('/tpTracks/1/playlists?filter[unaudited]'));
        self::assertSame(['1'], $this->ids('/tpAlbums?filter[auditor]=audrey'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    private static function migratedPivotTraversals(): iterable
    {
        yield 'a value through a pivot row relation' => ['legacyAddedBy=kim', 'addedBy=kim'];
        yield 'an id through a pivot row relation' => ['legacyAdder=1', 'adder=1'];
        yield 'a list of ids' => ['legacyAdder=1,2', 'adder=1,2'];
    }

    #[Test]
    public function aMigratedPivotTraversalMatchesWhatItReplaces(): void
    {
        foreach (self::migratedPivotTraversals() as $case => [$legacy, $migrated]) {
            foreach (['/tpTracks/1/playlists', '/tpTracks/1/relationships/playlists', '/tpTracks/3/playlists'] as $path) {
                self::assertSame($this->ids($path . '?filter[' . \str_replace('=', ']=', $legacy)), $this->ids($path . '?filter[' . \str_replace('=', ']=', $migrated)), $case . ' on ' . $path);
            }
        }
    }

    #[Test]
    public function aMigratedExistenceFilterMatchesWhatItReplaces(): void
    {
        self::assertSame($this->ids('/tpTracks/1/playlists?filter[legacyUnaudited]'), $this->ids('/tpTracks/1/playlists?filter[unaudited]'));
    }

    #[Test]
    public function bothFormsHideTheRowsTheReachedTypesScopeHides(): void
    {
        foreach ([['legacyAddedBy=ghost', 'addedBy=ghost'], ['legacyAdder=3', 'adder=3'], ['legacyAdder=1,3', 'adder=1,3']] as [$legacy, $migrated]) {
            foreach (['/tpTracks/2/playlists', '/tpTracks/2/relationships/playlists', '/tpTracks/1/playlists'] as $path) {
                $expected = $this->ids($path . '?filter[' . \str_replace('=', ']=', $migrated));
                self::assertSame($expected, $this->ids($path . '?filter[' . \str_replace('=', ']=', $legacy)), $legacy . ' on ' . $path);
            }
        }
        self::assertSame([], $this->ids('/tpTracks/2/playlists?filter[legacyAddedBy]=ghost'));
    }

    #[Test]
    public function bothFormsRefuseAHopTheCallerMayNotRead(): void
    {
        foreach ([['legacyAuditedBy]=audrey', 'auditedBy]=audrey'], ['legacyUnaudited]', 'unaudited]']] as [$legacy, $migrated]) {
            foreach (['ada' => 403, 'admin' => 200] as $user => $status) {
                $legacyResponse = $this->send($user, '/tpTracks/1/playlists?filter[' . $legacy);
                $migratedResponse = $this->send($user, '/tpTracks/1/playlists?filter[' . $migrated);
                self::assertSame($status, $legacyResponse->getStatusCode(), $legacy . ' for ' . $user . ': ' . $legacyResponse->getContent());
                self::assertSame($migratedResponse->getStatusCode(), $legacyResponse->getStatusCode(), $legacy . ' for ' . $user);
                if ($status === 403) {
                    self::assertSame(self::at($this->decode($migratedResponse), 'errors', '0', 'code'), self::at($this->decode($legacyResponse), 'errors', '0', 'code'));
                } else {
                    self::assertSame($this->idsOf($this->decode($migratedResponse)), $this->idsOf($this->decode($legacyResponse)), $legacy);
                }
            }
        }
    }

    #[Test]
    public function theDocumentTypesALeadingPivotFilterByItsField(): void
    {
        $document = $this->decode($this->send('admin', '/docs.json'));
        $parameters = self::at($document, 'paths', '/tpTracks/{id}/playlists', 'get', 'parameters');
        self::assertIsArray($parameters);
        $found = [];
        foreach ($parameters as $parameter) {
            if (\is_array($parameter) && \is_string($parameter['name'] ?? null)) {
                $found[$parameter['name']] = $parameter;
            }
        }
        self::assertSame(['type' => 'integer'], $found['filter[position]']['schema'] ?? null);
        self::assertSame('Filter the collection by `position`, on the pivot row.', $found['filter[position]']['description'] ?? null);
        self::assertSame(['type' => 'string'], $found['filter[addedBy]']['schema'] ?? null);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    private static function refusedDeclarations(): iterable
    {
        yield 'a leading pivot on a collection' => ['leading-pivot-on-collection', 'runs under none'];
        yield 'a leading pivot on a relation with no pivot rows' => ['plain-relation-pivot', 'which has no pivot rows'];
        yield 'a pivot after a relation with no pivot rows' => ['pivot-after-plain-to-many', 'which has no pivot rows'];
        yield 'a relation the pivot row does not hold' => ['unknown-pivot-row-relation', 'is not a relation the pivot rows of "playlists" hold'];
        yield 'a sort through a mid-path pivot' => ['mid-path-pivot-sort', 'whose `pivot` follows a to-many relation'];
        yield 'a pivot of an association entity with no stored pivot fields' => ['association-class-without-stored-fields', 'the Doctrine provider reads the pivot rows of a BelongsToMany with stored pivot fields over an association entity, and "barePlaylists" is not one.'];
        $unserved = 'which no registered type serves, so a path through it reaches no type whose rules apply.';
        $fix = 'Register exactly one resource type for that entity, or migrate the filter to a ->through() path that does not reach it.';
        yield 'a deprecated traversal past the pivot row to an entity no type serves' => ['legacy-through-untyped', 'declares the filter "noteText" of relation "playlists", whose deprecated path "pivot.note.text" goes on from the pivot row to an entity no single registered type serves'];
        yield 'the fix it names' => ['legacy-through-untyped', $fix];
        yield 'a deprecated list traversal past the pivot row' => ['legacy-through-in-untyped', 'the filter "note" of relation "playlists", whose deprecated path "pivot.note"'];
        yield 'a deprecated WhereHas past the pivot row' => ['legacy-has-untyped', 'the filter "noted" of relation "playlists", whose deprecated path "pivot.note"'];
        yield 'a deprecated WhereDoesntHave past the pivot row' => ['legacy-doesnt-have-untyped', 'the filter "unnoted" of relation "playlists", whose deprecated path "pivot.note"'];
        yield 'a WhereHasMatching past the pivot row' => ['legacy-has-matching-untyped', 'the filter "pinned" of relation "playlists", whose path "pivot.note" goes on from the pivot row to an entity no single registered type serves'];
        yield 'the fix a WhereHasMatching takes' => ['legacy-has-matching-untyped', 'Register exactly one resource type for that entity.'];
        yield 'a deprecated traversal inside a group' => ['legacy-grouped-untyped', 'the filter "noteSearch" of relation "playlists"'];
        yield 'the entity no type serves' => ['legacy-has-untyped', $unserved];
        yield 'an entity several types serve' => ['legacy-through-several', 'which several types serve (tpNotes, tpNoteAliases)'];
    }

    #[Test]
    public function aFamilyResolvingADeprecatedPathNoTypeCanGateAnswers500(): void
    {
        $response = $this->send('admin', '/tpFamilyTracks/1/playlists?filter[note.text]=pin');

        self::assertSame(500, $response->getStatusCode(), (string) $response->getContent());
        $logger = static::getContainer()->get('logger');
        self::assertInstanceOf(TpErrorLogger::class, $logger);
        self::assertCount(1, $logger->errors);
        self::assertStringContainsString('The filter family "note" of relation "playlists" on type "tpFamilyTracks" resolved the filter "note.text" to the path "pivot.note.text", which goes on from the pivot row to an entity no single registered type serves', $logger->errors[0]);
        self::assertStringContainsString('This is a declaration fault', $logger->errors[0]);

        // Without the key the relation reads as before.
        self::assertSame(['1', '2'], $this->ids('/tpFamilyTracks/1/playlists'));
    }

    #[Test]
    public function theWarmUpRefusesAPivotItCannotRun(): void
    {
        foreach (self::refusedDeclarations() as $case => [$declaration, $message]) {
            self::ensureKernelShutdown();
            ThroughPivotTestKernel::$broken = $declaration;

            try {
                self::bootKernel();
                self::fail(\sprintf('The kernel booted with %s.', $case));
            } catch (\LogicException $refused) {
                $messages = [];
                for ($e = $refused; $e !== null; $e = $e->getPrevious()) {
                    $messages[] = $e->getMessage();
                }
                self::assertStringContainsString($message, \implode(' | ', $messages), $case);
            }
        }
    }

    /**
     * @param array<string, string> $server
     *
     * @return list<string>
     */
    private function ids(string $path, array $server = []): array
    {
        $data = $this->document($path, $server)['data'] ?? null;
        self::assertIsArray($data);

        return \array_values(\array_map(static fn(mixed $row): string => \is_array($row) && \is_string($row['id'] ?? null) ? $row['id'] : '', $data));
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return list<string>
     */
    private function linkage(array $document, string $relation): array
    {
        $data = self::at($document, 'data', 'relationships', $relation, 'data');
        self::assertIsArray($data);

        return \array_values(\array_map(static fn(mixed $row): string => \is_array($row) && \is_string($row['id'] ?? null) ? $row['id'] : '', $data));
    }

    /**
     * @param array<string, string> $server
     *
     * @return array<string, mixed>
     */
    private function document(string $path, array $server = []): array
    {
        $response = $this->send('admin', $path, $server);
        self::assertSame(200, $response->getStatusCode(), $path . ': ' . $response->getContent());

        return $this->decode($response);
    }

    /**
     * @param array<string, string> $server
     */
    private function send(string $user, string $path, array $server = []): Response
    {
        $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $user;

        return $this->handle($path, 'GET', null, $server);
    }

    private static function at(mixed $value, string ...$keys): mixed
    {
        foreach ($keys as $key) {
            self::assertIsArray($value, $key);
            $value = $value[$key] ?? null;
        }

        return $value;
    }

    /**
     * The ids of every cursor page of `$path`, following each `next` link.
     *
     * @return list<string>
     */
    private function cursorPages(string $path): array
    {
        $ids = [];
        for ($page = 0; $path !== null && $page < 10; ++$page) {
            $document = $this->document($path);
            $ids = [...$ids, ...$this->idsOf($document)];
            $next = self::at($document, 'links', 'next');
            $href = \is_array($next) ? $next['href'] ?? null : $next;
            $path = \is_string($href) ? (string) \preg_replace('#^https?://[^/]+#', '', $href) : null;
        }

        return $ids;
    }

    /**
     * Each primary resource's linkage ids for `$relation`, keyed by its id.
     *
     * @param array<string, mixed> $document
     *
     * @return array<string, list<string>>
     */
    private function linkageByParent(array $document, string $relation): array
    {
        $data = self::at($document, 'data');
        self::assertIsArray($data);
        $byParent = [];
        foreach ($data as $resource) {
            self::assertIsArray($resource);
            $id = $resource['id'] ?? null;
            self::assertIsString($id);
            $byParent[$id] = $this->linkage(['data' => $resource], $relation);
        }
        \ksort($byParent);

        return $byParent;
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return list<string>
     */
    private function idsOf(array $document): array
    {
        $data = self::at($document, 'data');
        self::assertIsArray($data);

        return \array_values(\array_map(static fn(mixed $row): string => \is_array($row) && \is_string($row['id'] ?? null) ? $row['id'] : '', $data));
    }
}
