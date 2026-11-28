<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\EncodedPivot\EncodedPivotTestKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * An in-memory parent and member both behind id encoders: the seeded pivot rows key the
 * members by the ids the records keep, a write keys them by their wire ids, and every path
 * finds each member's row under its wire id.
 */
final class InMemoryEncodedPivotTest extends JsonApiFunctionalTestCase
{
    private const array SHIRTS = ['player-1' => ['shirt' => 7], 'player-2' => ['shirt' => 9]];

    protected static function getKernelClass(): string
    {
        return EncodedPivotTestKernel::class;
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function everyPathFindsEachMembersRowUnderItsWireId(): void
    {
        $document = $this->document('GET', '/enc-teams/team-1?include=players');
        self::assertSame(self::SHIRTS, $this->pivots($this->linkage($document['data'] ?? null)));
        self::assertSame(self::SHIRTS, $this->pivots($document['included'] ?? null));
        $collection = $this->document('GET', '/enc-teams')['data'] ?? null;
        self::assertIsArray($collection);
        self::assertSame(self::SHIRTS, $this->pivots($this->linkage($collection[0] ?? null)));
        self::assertSame(self::SHIRTS, $this->pivots($this->document('GET', '/enc-teams/team-1/players')['data'] ?? null));
        self::assertSame(self::SHIRTS, $this->pivots($this->document('GET', '/enc-teams/team-1/relationships/players')['data'] ?? null));
    }

    #[Test]
    #[Group('spec:updating-relationships')]
    public function aWriteStoresEachMembersPivotUnderItsWireId(): void
    {
        $written = $this->document('PATCH', '/enc-teams/team-1/relationships/players', ['data' => [
            ['type' => 'enc-players', 'id' => 'player-2', 'meta' => ['pivot' => ['shirt' => 10]]],
        ]]);

        self::assertSame(['player-2' => ['shirt' => 10]], $this->pivots($written['data'] ?? null));
        self::assertSame(['player-2' => ['shirt' => 10]], $this->pivots($this->linkage($this->document('GET', '/enc-teams/team-1')['data'] ?? null)));
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<mixed>
     */
    private function document(string $method, string $path, ?array $body = null): array
    {
        $response = $this->handle($path, $method, $body);
        self::assertSame(200, $response->getStatusCode(), $path . ': ' . $response->getContent());

        return $this->decode($response);
    }

    private function linkage(mixed $resource): mixed
    {
        self::assertIsArray($resource);
        $relationships = $resource['relationships'] ?? null;
        self::assertIsArray($relationships);
        $players = $relationships['players'] ?? null;
        self::assertIsArray($players);

        return $players['data'] ?? null;
    }

    /**
     * Each member's `meta.pivot`, keyed by id in id order.
     *
     * @return array<string, mixed>
     */
    private function pivots(mixed $members): array
    {
        self::assertIsArray($members);
        $pivots = [];
        foreach ($members as $member) {
            self::assertIsArray($member);
            self::assertIsString($member['id'] ?? null);
            $meta = $member['meta'] ?? null;
            self::assertIsArray($meta, (string) \json_encode($member));
            $pivots[$member['id']] = $meta['pivot'] ?? null;
        }
        \ksort($pivots);

        return $pivots;
    }
}
