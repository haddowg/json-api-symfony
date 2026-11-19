<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApi\Schema\Profile\RelationshipQueriesProfile;
use haddowg\JsonApiBundle\Tests\Functional\App\ThroughPivot\ThroughPivotTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\ThroughPivot\TpAlbumEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\ThroughPivot\TpAuditorEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\ThroughPivot\TpEntryEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\ThroughPivot\TpPlaylistEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\ThroughPivot\TpTrackEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\ThroughPivot\TpUserEntity;
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
    public function aPivotMidPathFiltersThroughTheRowsOfEachHop(): void
    {
        self::assertSame(['1', '2'], $this->ids('/tpAlbums?filter[adder]=kim'));
        self::assertSame(['1'], $this->ids('/tpAlbums?filter[adder]=sam'));
        self::assertSame(['2'], $this->ids('/tpAlbums?filter[addedAt][min]=2026-04-01T00:00:00Z'));
        self::assertSame(['1'], $this->ids('/tpAlbums?filter[addedAt][max]=2026-02-15T00:00:00Z'));
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
    public function theMigratedFormAppliesTheReachedTypesScopeWhereThePrefixedOneDidNot(): void
    {
        self::assertSame(['1'], $this->ids('/tpTracks/2/playlists?filter[legacyAddedBy]=ghost'));
        self::assertSame([], $this->ids('/tpTracks/2/playlists?filter[addedBy]=ghost'));
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
}
