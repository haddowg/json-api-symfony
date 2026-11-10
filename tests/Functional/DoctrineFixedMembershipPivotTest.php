<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\FixedMembershipDoctrineTestKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * A fixed membership on a pivot-backed `belongsToMany` (Doctrine only, as pivots are):
 * the current members are the far tracks of the playlist's association entities, so a
 * track held at two positions is one member.
 */
final class DoctrineFixedMembershipPivotTest extends JsonApiFunctionalTestCase
{
    use SeedsDoctrinePivot;

    protected static function getKernelClass(): string
    {
        return FixedMembershipDoctrineTestKernel::class;
    }

    #[Test]
    #[Group('spec:updating-relationships')]
    public function aReplacementThatReordersTheTracksIsAllowed(): void
    {
        $response = $this->handle('/playlists/1/relationships/tracks', 'PATCH', ['data' => [
            ['type' => 'tracks', 'id' => '3'],
            ['type' => 'tracks', 'id' => '1'],
            ['type' => 'tracks', 'id' => '2'],
        ]]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['1', '2', '3'], $this->trackIds('1'));
    }

    #[Test]
    #[Group('spec:updating-relationships')]
    public function aTrackHeldTwiceIsOneMember(): void
    {
        $response = $this->handle('/playlists/3/relationships/tracks', 'PATCH', ['data' => [
            ['type' => 'tracks', 'id' => '2'],
            ['type' => 'tracks', 'id' => '1'],
        ]]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    }

    #[Test]
    #[Group('spec:updating-relationships')]
    public function aReplacementThatDropsATrackIsForbidden(): void
    {
        $response = $this->handle('/playlists/1/relationships/tracks', 'PATCH', ['data' => [
            ['type' => 'tracks', 'id' => '1'],
            ['type' => 'tracks', 'id' => '2'],
        ]]);

        $this->assertProhibited($response);
        self::assertSame(['1', '2', '3'], $this->trackIds('1'));
    }

    #[Test]
    #[Group('spec:updating-resources')]
    public function aWholeResourceUpdateThatChangesTheTracksIsForbidden(): void
    {
        $response = $this->handle('/playlists/2', 'PATCH', ['data' => [
            'type' => 'playlists',
            'id' => '2',
            'relationships' => ['tracks' => ['data' => [
                ['type' => 'tracks', 'id' => '1'],
                ['type' => 'tracks', 'id' => '3'],
            ]]],
        ]]);

        $this->assertProhibited($response);
        self::assertSame(['1'], $this->trackIds('2'));
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
     * @return list<string>
     */
    private function trackIds(string $playlist): array
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);
        $entityManager->clear();

        $response = $this->handle('/playlists/' . $playlist . '/relationships/tracks');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $ids = [];
        foreach ((array) ($this->decode($response)['data'] ?? []) as $identifier) {
            self::assertIsArray($identifier);
            self::assertIsString($identifier['id'] ?? null);
            $ids[] = $identifier['id'];
        }
        $ids = \array_values(\array_unique($ids));
        \sort($ids);

        return $ids;
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
