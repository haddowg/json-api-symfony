<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\FixedMembershipThroughDoctrineTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\PlaylistEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\PlaylistTrackEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\TrackEntity;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * A fixed membership on a pivot `belongsToMany` declared with `through()` and no
 * `extractUsing()`, so the playlist holds its association rows rather than its tracks.
 * The current members are the tracks those rows reach. Pivots are Doctrine only, since
 * the in-memory store holds no association rows.
 *
 * The rows of playlist 3 come first, so no playlist's row ids match its track ids.
 * Playlist 1 holds tracks 1 and 2 through rows 5 and 6, and tracks 5 and 6 exist, so a
 * replacement naming them would read as unchanged if the rows were taken for members.
 * Playlist 2 holds track 3 and track 4, which the scope hides.
 */
final class DoctrineFixedMembershipThroughTest extends JsonApiFunctionalTestCase
{
    protected static function getKernelClass(): string
    {
        return FixedMembershipThroughDoctrineTestKernel::class;
    }

    protected function afterBoot(): void
    {
        $entityManager = $this->entityManager();
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        $tracks = [];
        foreach (['Intro', 'Outro', 'Bridge', 'Hidden', 'Coda', 'Reprise'] as $title) {
            $entityManager->persist($tracks[] = new TrackEntity(title: $title));
        }

        $set = new PlaylistEntity(name: 'Set');
        $encore = new PlaylistEntity(name: 'Encore');
        $other = new PlaylistEntity(name: 'Other');
        foreach ([$set, $encore, $other] as $playlist) {
            $entityManager->persist($playlist);
        }

        $rows = [
            [$other, $tracks[0]], [$other, $tracks[1]], [$other, $tracks[2]], [$other, $tracks[4]],
            [$set, $tracks[0]], [$set, $tracks[1]],
            [$encore, $tracks[2]], [$encore, $tracks[3]],
        ];
        $positions = [];
        foreach ($rows as [$playlist, $track]) {
            $position = $positions[\spl_object_id($playlist)] = ($positions[\spl_object_id($playlist)] ?? 0) + 1;
            $entityManager->persist(new PlaylistTrackEntity(playlist: $playlist, track: $track, position: $position));
        }

        $entityManager->flush();
        $entityManager->clear();
    }

    #[Test]
    #[Group('spec:updating-relationships')]
    public function aReplacementThatReordersTheTracksIsAllowed(): void
    {
        $response = $this->handle('/playlists/1/relationships/tracks', 'PATCH', ['data' => [
            ['type' => 'tracks', 'id' => '2'],
            ['type' => 'tracks', 'id' => '1'],
        ]]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['1', '2'], $this->storedTracks(1));
    }

    #[Test]
    #[Group('spec:updating-relationships')]
    public function aReplacementThatAddsATrackIsForbidden(): void
    {
        $response = $this->handle('/playlists/1/relationships/tracks', 'PATCH', ['data' => [
            ['type' => 'tracks', 'id' => '1'],
            ['type' => 'tracks', 'id' => '2'],
            ['type' => 'tracks', 'id' => '3'],
        ]]);

        $this->assertProhibited($response);
        self::assertSame(['1', '2'], $this->storedTracks(1));
    }

    #[Test]
    #[Group('spec:updating-relationships')]
    public function aReplacementThatDropsATrackIsForbidden(): void
    {
        $response = $this->handle('/playlists/1/relationships/tracks', 'PATCH', ['data' => [
            ['type' => 'tracks', 'id' => '1'],
        ]]);

        $this->assertProhibited($response);
        self::assertSame(['1', '2'], $this->storedTracks(1));
    }

    #[Test]
    #[Group('spec:updating-relationships')]
    public function aReplacementNamingTheRowIdsIsForbidden(): void
    {
        $response = $this->handle('/playlists/1/relationships/tracks', 'PATCH', ['data' => [
            ['type' => 'tracks', 'id' => '5'],
            ['type' => 'tracks', 'id' => '6'],
        ]]);

        $this->assertProhibited($response);
        self::assertSame(['1', '2'], $this->storedTracks(1));
    }

    #[Test]
    #[Group('spec:updating-relationships')]
    public function addingAndRemovingAreClosed(): void
    {
        $add = $this->handle('/playlists/1/relationships/tracks', 'POST', ['data' => [['type' => 'tracks', 'id' => '3']]]);
        self::assertSame(403, $add->getStatusCode(), (string) $add->getContent());
        self::assertSame('ADDITION_PROHIBITED', self::dig($this->decode($add), 'errors', 0, 'code'));

        $remove = $this->handle('/playlists/1/relationships/tracks', 'DELETE', ['data' => [['type' => 'tracks', 'id' => '1']]]);
        self::assertSame(403, $remove->getStatusCode(), (string) $remove->getContent());
        self::assertSame('REMOVAL_PROHIBITED', self::dig($this->decode($remove), 'errors', 0, 'code'));

        self::assertSame(['1', '2'], $this->storedTracks(1));
    }

    #[Test]
    #[Group('spec:updating-relationships')]
    public function aReplacementListingTheVisibleTracksKeepsTheHiddenOne(): void
    {
        self::assertSame(['3'], $this->visibleTracks(2));

        $response = $this->handle('/playlists/2/relationships/tracks', 'PATCH', ['data' => [
            ['type' => 'tracks', 'id' => '3'],
        ]]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['3', '4'], $this->storedTracks(2));
    }

    #[Test]
    #[Group('spec:updating-relationships')]
    public function aReplacementDroppingTheVisibleTrackBesideAHiddenOneIsForbidden(): void
    {
        $response = $this->handle('/playlists/2/relationships/tracks', 'PATCH', ['data' => []]);

        $this->assertProhibited($response);
        self::assertSame(['3', '4'], $this->storedTracks(2));
    }

    #[Test]
    #[Group('spec:updating-resources')]
    public function aWholeResourceUpdateThatReordersTheTracksIsAllowed(): void
    {
        $response = $this->handle('/playlists/1', 'PATCH', ['data' => [
            'type' => 'playlists',
            'id' => '1',
            'attributes' => ['name' => 'Reordered'],
            'relationships' => ['tracks' => ['data' => [
                ['type' => 'tracks', 'id' => '2'],
                ['type' => 'tracks', 'id' => '1'],
            ]]],
        ]]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['1', '2'], $this->storedTracks(1));
        self::assertSame('Reordered', $this->playlistName(1));
    }

    #[Test]
    #[Group('spec:updating-resources')]
    public function aWholeResourceUpdateThatChangesTheTracksIsForbidden(): void
    {
        $response = $this->handle('/playlists/1', 'PATCH', ['data' => [
            'type' => 'playlists',
            'id' => '1',
            'attributes' => ['name' => 'Changed'],
            'relationships' => ['tracks' => ['data' => [
                ['type' => 'tracks', 'id' => '5'],
                ['type' => 'tracks', 'id' => '6'],
            ]]],
        ]]);

        $this->assertProhibited($response);
        self::assertSame(['1', '2'], $this->storedTracks(1));
        self::assertSame('Set', $this->playlistName(1));
    }

    #[Test]
    #[Group('spec:atomic')]
    public function anAtomicReorderIsAllowed(): void
    {
        $response = $this->atomic([
            [
                'op' => 'update',
                'ref' => ['type' => 'playlists', 'id' => '1', 'relationship' => 'tracks'],
                'data' => [['type' => 'tracks', 'id' => '2'], ['type' => 'tracks', 'id' => '1']],
            ],
        ]);

        self::assertContains($response->getStatusCode(), [200, 204], (string) $response->getContent());
        self::assertSame(['1', '2'], $this->storedTracks(1));
    }

    #[Test]
    #[Group('spec:atomic')]
    public function anAtomicReplacementThatChangesTheTracksRollsBackTheBatch(): void
    {
        $response = $this->atomic([
            [
                'op' => 'update',
                'ref' => ['type' => 'playlists', 'id' => '1'],
                'data' => ['type' => 'playlists', 'id' => '1', 'attributes' => ['name' => 'Rolled back']],
            ],
            [
                'op' => 'update',
                'ref' => ['type' => 'playlists', 'id' => '1', 'relationship' => 'tracks'],
                'data' => [['type' => 'tracks', 'id' => '5'], ['type' => 'tracks', 'id' => '6']],
            ],
        ]);

        self::assertSame(403, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('MEMBERSHIP_CHANGE_PROHIBITED', self::dig($this->decode($response), 'errors', 0, 'code'));
        self::assertSame('Set', $this->playlistName(1));
        self::assertSame(['1', '2'], $this->storedTracks(1));
    }

    private function assertProhibited(Response $response): void
    {
        self::assertSame(403, $response->getStatusCode(), (string) $response->getContent());
        $error = self::dig($this->decode($response), 'errors', 0);
        self::assertIsArray($error);
        self::assertSame('MEMBERSHIP_CHANGE_PROHIBITED', $error['code'] ?? null);
        self::assertSame('/data/relationships/tracks', self::dig($error, 'source', 'pointer'));
    }

    /**
     * @return list<string> the ids of the tracks the playlist's rows reach, hidden ones included, sorted
     */
    private function storedTracks(int $playlist): array
    {
        $entityManager = $this->entityManager();
        $entityManager->clear();

        $ids = [];
        foreach ($entityManager->getRepository(PlaylistTrackEntity::class)->findBy(['playlist' => $playlist]) as $row) {
            if ($row->track?->id !== null) {
                $ids[] = (string) $row->track->id;
            }
        }
        $ids = \array_values(\array_unique($ids));
        \sort($ids);

        return $ids;
    }

    /**
     * @return list<string> the playlist's track ids its relationship endpoint renders, sorted
     */
    private function visibleTracks(int $playlist): array
    {
        $this->entityManager()->clear();
        $response = $this->handle('/playlists/' . $playlist . '/relationships/tracks');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $ids = [];
        foreach ((array) ($this->decode($response)['data'] ?? []) as $identifier) {
            self::assertIsArray($identifier);
            self::assertIsString($identifier['id'] ?? null);
            $ids[] = $identifier['id'];
        }
        \sort($ids);

        return $ids;
    }

    private function playlistName(int $playlist): ?string
    {
        $this->entityManager()->clear();

        return $this->entityManager()->find(PlaylistEntity::class, $playlist)?->name;
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        return $entityManager;
    }

    /**
     * @param list<array<string, mixed>> $operations
     */
    private function atomic(array $operations): Response
    {
        $kernel = static::$kernel;
        self::assertNotNull($kernel);

        $ext = 'application/vnd.api+json; ext="https://jsonapi.org/ext/atomic"';
        $request = Request::create('/operations', 'POST', server: ['CONTENT_TYPE' => $ext, 'HTTP_ACCEPT' => $ext], content: \json_encode(['atomic:operations' => $operations], \JSON_THROW_ON_ERROR));
        $response = $kernel->handle($request, HttpKernelInterface::MAIN_REQUEST, true);
        $this->restoreHandlers();

        return $response;
    }

    /**
     * The value at `$keys` inside `$value`, or `null` when a step is missing.
     */
    private static function dig(mixed $value, string|int ...$keys): mixed
    {
        foreach ($keys as $key) {
            if (!\is_array($value) || !\array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }
}
