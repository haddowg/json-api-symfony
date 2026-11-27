<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApi\Schema\Profile\CountableProfile;
use haddowg\JsonApiBundle\Tests\Functional\App\PivotIdForms\PifCoach;
use haddowg\JsonApiBundle\Tests\Functional\App\PivotIdForms\PifPlayer;
use haddowg\JsonApiBundle\Tests\Functional\App\PivotIdForms\PifTeam;
use haddowg\JsonApiBundle\Tests\Functional\App\PivotIdForms\PivotIdFormsTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\QueryCountingLogger;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * Pivot meta where the two ends of a pivot keep their keys in different forms: teams
 * are keyed by a binary UUID, players by a UUID string their own column keeps in upper
 * case while the seat's player column holds it in lower case, and both types encode
 * their ids. A seat must still find its team and player wherever the pivot renders.
 * A coach keyed by a guid kept in capitals is addressed in either case, rendered in
 * lower case, and linked by its stored key. Doctrine-only: the in-memory provider has no database key forms.
 */
#[Group('spec:crud')]
final class DoctrinePivotIdFormsTest extends JsonApiFunctionalTestCase
{
    private const string RED = '01932f5e-1a2b-7c3d-8e4f-00000000000a';

    private const string BLUE = '01932f5e-1a2b-7c3d-8e4f-00000000000b';

    private const string ANN = 'A1B2C3D4-E5F6-4A7B-8C9D-0E1F2A3B4C01';

    private const string BOB = 'A1B2C3D4-E5F6-4A7B-8C9D-0E1F2A3B4C02';

    private const string COACH = 'C0AC4000-E5F6-4A7B-8C9D-0E1F2A3B4C01';

    protected static function getKernelClass(): string
    {
        return PivotIdFormsTestKernel::class;
    }

    protected function afterBoot(): void
    {
        $entityManager = $this->entityManager();
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        $entityManager->persist(new PifTeam(Uuid::fromString(self::RED), 'red'));
        $entityManager->persist(new PifTeam(Uuid::fromString(self::BLUE), 'blue'));
        $entityManager->persist(new PifPlayer(self::ANN, 'ann'));
        $entityManager->persist(new PifPlayer(self::BOB, 'bob'));
        $entityManager->persist(new PifCoach(self::COACH, 'cat'));
        $entityManager->flush();
        $entityManager->clear();

        // The seat's player column holds the key in lower case, as a column a
        // case-insensitive collation matches to the player's own key.
        foreach ([[self::RED, self::ANN, 'captain'], [self::RED, self::BOB, 'keeper'], [self::BLUE, self::ANN, 'striker']] as [$team, $player, $role]) {
            $entityManager->getConnection()->insert(
                'pif_seat',
                ['teamId' => Uuid::fromString($team), 'playerId' => \strtolower($player), 'role' => $role],
                ['teamId' => 'uuid'],
            );
        }
    }

    #[Test]
    public function aTeamRendersEachPlayersPivot(): void
    {
        $document = $this->get('/pif-teams/' . self::team(self::RED) . '?include=players');

        self::assertSame([self::player(self::ANN) => 'captain', self::player(self::BOB) => 'keeper'], $this->roles($this->data($document), 'players'));
        self::assertSame([self::player(self::ANN) => 'captain', self::player(self::BOB) => 'keeper'], $this->includedRoles($document, 'pif-players'));
    }

    #[Test]
    public function aPlayerRendersEachTeamsPivot(): void
    {
        $expected = [self::team(self::RED) => 'captain', self::team(self::BLUE) => 'striker'];

        self::assertSame($expected, $this->roles($this->data($this->get('/pif-players/' . self::player(self::ANN))), 'teams'));
        self::assertSame($expected, $this->roles($this->data($this->get('/pif-players/' . self::player(self::ANN) . '?include=teams')), 'teams'));
        self::assertSame([self::team(self::RED) => 'keeper'], $this->roles($this->data($this->get('/pif-players/' . self::player(self::BOB))), 'teams'));
    }

    #[Test]
    public function aNestedIncludeRendersEachPlayersTeamsPivot(): void
    {
        $document = $this->get('/pif-teams/' . self::team(self::RED) . '?include=players.teams');

        $players = $this->included($document, 'pif-players');
        self::assertSame([self::team(self::RED) => 'captain', self::team(self::BLUE) => 'striker'], $this->roles($players[self::player(self::ANN)] ?? [], 'teams'));
        self::assertSame([self::team(self::RED) => 'keeper'], $this->roles($players[self::player(self::BOB)] ?? [], 'teams'));
    }

    #[Test]
    public function aNestedIncludeUnderACollectionRendersEachPlayersTeamsPivot(): void
    {
        $document = $this->get('/pif-teams?include=players.teams');

        $players = $this->included($document, 'pif-players');
        self::assertSame([self::team(self::RED) => 'captain', self::team(self::BLUE) => 'striker'], $this->roles($players[self::player(self::ANN)] ?? [], 'teams'));
        self::assertSame([self::team(self::RED) => 'keeper'], $this->roles($players[self::player(self::BOB)] ?? [], 'teams'));

        $teams = $this->included($document, 'pif-teams');
        self::assertSame([self::player(self::ANN) => 'striker'], $this->roles($teams[self::team(self::BLUE)] ?? $this->rowOf($document, self::team(self::BLUE)), 'players'));
    }

    #[Test]
    public function aRelatedEndpointIncludeRendersEachPlayersTeamsPivot(): void
    {
        $document = $this->get('/pif-teams/' . self::team(self::RED) . '/players?include=teams');

        $rows = $this->rows($document);
        self::assertSame(['captain', 'keeper'], \array_map(fn(array $row): mixed => $this->pivotOf($row)['role'] ?? null, $rows));
        self::assertSame([self::team(self::RED) => 'captain', self::team(self::BLUE) => 'striker'], $this->roles($rows[0], 'teams'));
        self::assertSame([self::team(self::RED) => 'keeper'], $this->roles($rows[1], 'teams'));
    }

    #[Test]
    public function aWriteResponseRendersEachPlayersTeamsPivot(): void
    {
        $response = $this->handle('/pif-teams/' . self::team(self::RED) . '?include=players.teams', 'PATCH', ['data' => [
            'type' => 'pif-teams',
            'id' => self::team(self::RED),
            'attributes' => ['name' => 'crimson'],
        ]]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $document = $this->decode($response);

        self::assertSame([self::player(self::ANN) => 'captain', self::player(self::BOB) => 'keeper'], $this->roles($this->data($document), 'players'));
        $players = $this->included($document, 'pif-players');
        self::assertSame([self::team(self::RED) => 'captain', self::team(self::BLUE) => 'striker'], $this->roles($players[self::player(self::ANN)] ?? [], 'teams'));
    }

    #[Test]
    public function aPlayerWhoseSeatsKeepItsKeyInBothCasesIsOnePartition(): void
    {
        // Bob's red seat keeps his key in lower case, and this one in capitals.
        $this->entityManager()->getConnection()->insert(
            'pif_seat',
            ['teamId' => Uuid::fromString(self::BLUE), 'playerId' => self::BOB, 'role' => 'bench'],
            ['teamId' => 'uuid'],
        );

        $logger = static::getContainer()->get(QueryCountingLogger::class);
        self::assertInstanceOf(QueryCountingLogger::class, $logger);
        $logger->reset();
        $players = $this->get('/pif-players?include=teams');
        $seats = \array_values(\array_filter(\array_map('\\strtolower', $logger->statements()), static fn(string $sql): bool => \str_contains($sql, 'from pif_team') && \str_contains($sql, 'pif_seat')));

        $rows = [];
        foreach ($this->rows($players) as $row) {
            self::assertIsString($row['id'] ?? null);
            $rows[$row['id']] = $row;
        }
        self::assertSame([self::team(self::RED) => 'captain', self::team(self::BLUE) => 'striker'], $this->roles($rows[self::player(self::ANN)] ?? [], 'teams'));
        self::assertSame([self::team(self::RED) => 'keeper', self::team(self::BLUE) => 'bench'], $this->roles($rows[self::player(self::BOB)] ?? [], 'teams'));

        self::assertCount(1, $seats, \implode("\n", $logger->statements()));
        self::assertStringContainsString('partition by', $seats[0]);
        self::assertStringContainsString('lower(', $seats[0]);
        // The column itself is compared as stored, so an index on it serves the query.
        self::assertStringNotContainsString('lower(p1_.playerid) in', $seats[0]);
    }

    #[Test]
    public function aPivotMemberKeyedInCapitalsWithNoEncoderKeepsItsIncludesCountsAndPivot(): void
    {
        [$ann, $bob] = [\strtolower(self::ANN), \strtolower(self::BOB)];
        $counts = ['HTTP_ACCEPT' => 'application/vnd.api+json;profile="' . CountableProfile::URI . '"'];

        $team = $this->get('/pif-teams/' . self::team(self::RED) . '?include=squad.teams');
        self::assertSame([$ann => 'captain', $bob => 'keeper'], $this->roles($this->data($team), 'squad'));
        $squads = $this->included($team, 'pif-squads');
        self::assertSame([$ann, $bob], \array_keys($squads));
        self::assertSame([$ann => 'captain', $bob => 'keeper'], $this->includedRoles($team, 'pif-squads'));
        self::assertSame([self::team(self::RED) => 'captain', self::team(self::BLUE) => 'striker'], $this->roles($squads[$ann], 'teams'));

        foreach ([$ann, self::ANN] as $id) {
            $squad = $this->get('/pif-squads/' . $id . '?include=teams');
            self::assertSame($ann, $this->data($squad)['id'] ?? null);
            self::assertSame([self::team(self::RED) => 'captain', self::team(self::BLUE) => 'striker'], $this->roles($this->data($squad), 'teams'));
        }

        $listed = $this->decode($this->handle('/pif-squads?withCount=teams&include=teams', extraServer: $counts));
        $totals = [];
        foreach ($this->rows($listed) as $row) {
            self::assertIsString($row['id'] ?? null);
            $relationships = $row['relationships'] ?? null;
            self::assertIsArray($relationships);
            $teams = $relationships['teams'] ?? null;
            self::assertIsArray($teams);
            $meta = $teams['meta'] ?? null;
            self::assertIsArray($meta);
            $totals[$row['id']] = $meta['total'] ?? null;
        }
        self::assertSame([$ann => 2, $bob => 1], $totals);

        $related = $this->rows($this->get('/pif-teams/' . self::team(self::RED) . '/squad'));
        self::assertSame([$ann, $bob], \array_column($related, 'id'));
        self::assertSame(['captain', 'keeper'], \array_map(fn(array $row): mixed => $this->pivotOf($row)['role'] ?? null, $related));
    }

    #[Test]
    public function aGuidKeptInCapitalsIsAddressedInEitherCaseAndRenderedInLowerCase(): void
    {
        $wire = \strtolower(self::COACH);

        foreach ([$wire, self::COACH] as $id) {
            self::assertSame($wire, $this->data($this->get('/pif-coaches/' . $id))['id'] ?? null);
        }

        $renamed = $this->handle('/pif-coaches/' . $wire, 'PATCH', ['data' => ['type' => 'pif-coaches', 'id' => $wire, 'attributes' => ['name' => 'kit']]]);
        self::assertSame(200, $renamed->getStatusCode(), (string) $renamed->getContent());
        self::assertSame($wire, $this->data($this->decode($renamed))['id'] ?? null);
    }

    #[Test]
    public function aLinkageToAGuidKeptInCapitalsStoresTheStoredKey(): void
    {
        $wire = \strtolower(self::COACH);

        $response = $this->handle('/pif-teams/' . self::team(self::RED) . '?include=coach', 'PATCH', ['data' => [
            'type' => 'pif-teams',
            'id' => self::team(self::RED),
            'relationships' => ['coach' => ['data' => ['type' => 'pif-coaches', 'id' => $wire]]],
        ]]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $document = $this->decode($response);
        self::assertSame(['type' => 'pif-coaches', 'id' => $wire], $this->toOne($this->data($document), 'coach'));
        self::assertSame([$wire], \array_keys($this->included($document, 'pif-coaches')));

        $relationship = $this->handle('/pif-teams/' . self::team(self::BLUE) . '/relationships/coach', 'PATCH', ['data' => ['type' => 'pif-coaches', 'id' => self::COACH]]);
        self::assertContains($relationship->getStatusCode(), [200, 204], (string) $relationship->getContent());

        $entityManager = $this->entityManager();
        $entityManager->clear();
        foreach ([self::RED, self::BLUE] as $team) {
            self::assertSame(self::COACH, $entityManager->getConnection()->fetchOne('SELECT coachId FROM pif_team WHERE id = ?', [Uuid::fromString($team)], ['uuid']));
        }
        self::assertSame(['type' => 'pif-coaches', 'id' => $wire], $this->toOne($this->data($this->get('/pif-teams/' . self::team(self::BLUE) . '?include=coach')), 'coach'));
    }

    #[Test]
    public function aPivotLinkageRenderedWithoutAnIncludeCarriesEachMembersPivot(): void
    {
        // A team's squad reads its members through the seats whatever was included.
        [$ann, $bob] = [\strtolower(self::ANN), \strtolower(self::BOB)];
        $red = self::team(self::RED);

        self::assertSame([$ann => 'captain', $bob => 'keeper'], $this->roles($this->data($this->get('/pif-teams/' . $red)), 'squad'));
        self::assertSame([$ann => 'striker'], $this->roles($this->rowOf($this->get('/pif-teams?fields[pif-teams]=squad'), self::team(self::BLUE)), 'squad'));
        $teams = $this->included($this->get('/pif-players/' . self::player(self::ANN) . '?include=teams'), 'pif-teams');
        self::assertSame([$ann => 'captain', $bob => 'keeper'], $this->roles($teams[$red] ?? [], 'squad'));
        self::assertSame([$ann => 'striker'], $this->roles($teams[self::team(self::BLUE)] ?? [], 'squad'));

        $response = $this->handle('/pif-teams/' . $red, 'PATCH', ['data' => [
            'type' => 'pif-teams',
            'id' => $red,
            'relationships' => ['squad' => ['data' => [['type' => 'pif-squads', 'id' => self::BOB, 'meta' => ['pivot' => ['role' => 'sub']]]]]],
        ]]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame([$bob => 'sub'], $this->roles($this->data($this->decode($response)), 'squad'));
    }

    #[Test]
    public function aTraversalEndingOnARelationMatchesAGuidKeptInCapitalsInEitherCase(): void
    {
        $this->entityManager()->getConnection()->executeStatement('UPDATE pif_team SET coachId = ? WHERE id = ?', [self::COACH, Uuid::fromString(self::RED)], [\Doctrine\DBAL\ParameterType::STRING, 'uuid']);
        $red = self::team(self::RED);

        foreach ([\strtolower(self::COACH), self::COACH] as $sent) {
            self::assertSame([$red], \array_column($this->rows($this->get('/pif-teams?filter[coachIs]=' . $sent)), 'id'), $sent);
            self::assertSame([$red], \array_column($this->rows($this->get('/pif-teams?filter[coachIn]=' . $sent)), 'id'), $sent);
            // Only red has a coach, and it is the one named.
            self::assertSame([], \array_column($this->rows($this->get('/pif-teams?filter[coachNot]=' . $sent)), 'id'), $sent);
        }
    }

    #[Test]
    public function aPivotRowFilterMatchesAUuidValueInEitherCase(): void
    {
        $value = 'B0B0B0B0-E5F6-4A7B-8C9D-0E1F2A3B4C99';
        $this->entityManager()->getConnection()->executeStatement('UPDATE pif_seat SET role = ? WHERE role = ?', [$value, 'keeper']);
        $players = fn(string $query): array => \array_column($this->rows($this->get('/pif-teams/' . self::team(self::RED) . '/players?' . $query)), 'id');
        [$ann, $bob] = [self::player(self::ANN), self::player(self::BOB)];

        foreach ([\strtolower($value), $value] as $sent) {
            self::assertSame([$bob], $players('filter[roleIs]=' . $sent), $sent);
            self::assertSame([$bob], $players('filter[roleIn]=' . $sent), $sent);
            self::assertSame([$bob], $players('filter[seatRole]=' . $sent), $sent);
            self::assertSame([$ann], $players('filter[roleNot]=' . $sent), $sent);
            self::assertSame([$ann], $players('filter[roleNotIn]=' . $sent), $sent);
        }
        // A value that is no uuid still compares exactly.
        self::assertSame([], $players('filter[roleIs]=CAPTAIN'));
        self::assertSame([$ann], $players('filter[roleIs]=captain'));
    }

    /**
     * @param array<string, mixed> $resource
     */
    private function toOne(array $resource, string $relation): mixed
    {
        $relationships = $resource['relationships'] ?? null;
        self::assertIsArray($relationships);
        $object = $relationships[$relation] ?? null;
        self::assertIsArray($object);

        return $object['data'] ?? null;
    }

    private static function team(string $uuid): string
    {
        return 'team-' . $uuid;
    }

    private static function player(string $key): string
    {
        return 'pl-' . $key;
    }

    /**
     * @return array<string, mixed>
     */
    private function get(string $path): array
    {
        $response = $this->handle($path);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return $this->decode($response);
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private function data(array $document): array
    {
        $data = $document['data'] ?? null;
        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return list<array<string, mixed>>
     */
    private function rows(array $document): array
    {
        $rows = $document['data'] ?? null;
        self::assertIsArray($rows);

        /** @var list<array<string, mixed>> $rows */
        return $rows;
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private function rowOf(array $document, string $id): array
    {
        foreach ($this->rows($document) as $row) {
            if (($row['id'] ?? null) === $id) {
                return $row;
            }
        }

        self::fail(\sprintf('No primary resource %s.', $id));
    }

    /**
     * The included resources of `$type`, keyed by id.
     *
     * @param array<string, mixed> $document
     *
     * @return array<string, array<string, mixed>>
     */
    private function included(array $document, string $type): array
    {
        $included = $document['included'] ?? [];
        self::assertIsArray($included);

        $byId = [];
        foreach ($included as $resource) {
            self::assertIsArray($resource);
            $id = $resource['id'] ?? null;
            if (($resource['type'] ?? null) === $type && \is_string($id)) {
                /** @var array<string, mixed> $resource */
                $byId[$id] = $resource;
            }
        }
        \ksort($byId);

        return $byId;
    }

    /**
     * Each included resource of `$type`'s pivot role, keyed by id.
     *
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private function includedRoles(array $document, string $type): array
    {
        return \array_map(fn(array $resource): mixed => $this->pivotOf($resource)['role'] ?? null, $this->included($document, $type));
    }

    /**
     * The pivot role on each identifier of `$relation`'s linkage, keyed by id.
     *
     * @param array<string, mixed> $resource
     *
     * @return array<string, mixed>
     */
    private function roles(array $resource, string $relation): array
    {
        $relationships = $resource['relationships'] ?? null;
        self::assertIsArray($relationships, 'the resource renders relationships');
        $object = $relationships[$relation] ?? null;
        self::assertIsArray($object);
        $linkage = $object['data'] ?? null;
        self::assertIsArray($linkage, \sprintf('the %s relationship renders data', $relation));

        $roles = [];
        foreach ($linkage as $identifier) {
            self::assertIsArray($identifier);
            self::assertIsString($identifier['id'] ?? null);
            $meta = $identifier['meta'] ?? [];
            self::assertIsArray($meta);
            $pivot = $meta['pivot'] ?? [];
            self::assertIsArray($pivot);
            $roles[$identifier['id']] = $pivot['role'] ?? null;
        }
        \ksort($roles);

        return $roles;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function pivotOf(array $row): array
    {
        $meta = $row['meta'] ?? [];
        self::assertIsArray($meta);
        $pivot = $meta['pivot'] ?? [];
        self::assertIsArray($pivot);

        /** @var array<string, mixed> $pivot */
        return $pivot;
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        return $entityManager;
    }
}
