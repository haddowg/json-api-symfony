<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApi\Schema\Profile\CountableProfile;
use haddowg\JsonApiBundle\Tests\Functional\App\SelfThrough\StFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * A to-many from an owner to owners of its own type, read from both sides and written from
 * the owner's. Owner 1 seats owner 2 and owner 2 seats owner 3, for an integer key, a uuid
 * behind an encoder and a guid kept in capitals. A relation reading the wrong side of a seat
 * would find owner 1 among its own peers, or none at all.
 */
abstract class SelfThroughConformanceTestCase extends JsonApiFunctionalTestCase
{
    private const string COUNTS_ACCEPT = 'application/vnd.api+json;profile="' . CountableProfile::URI . '"';

    /**
     * The owner numbers stored as `$relation` of owner `$n`.
     *
     * @return list<int>
     */
    abstract protected function stored(string $type, int $n, string $relation): array;

    /**
     * @return iterable<string, array{string}>
     */
    public static function types(): iterable
    {
        foreach (\array_keys(StFixtures::TYPES) as $type) {
            yield $type => [$type];
        }
    }

    #[Test]
    #[DataProvider('types')]
    public function theLinkageNamesTheOwnersOnTheRelationsOwnSide(string $type): void
    {
        self::assertSame([2], $this->linkage($type, 1, 'peers'));
        self::assertSame([3], $this->linkage($type, 2, 'peers'));
        self::assertSame([], $this->linkage($type, 3, 'peers'));
        self::assertSame([], $this->linkage($type, 1, 'followers'));
        self::assertSame([1], $this->linkage($type, 2, 'followers'));
        self::assertSame([2], $this->linkage($type, 3, 'followers'));
    }

    #[Test]
    #[DataProvider('types')]
    public function theRelatedEndpointServesTheOwnersOnTheRelationsOwnSide(string $type): void
    {
        self::assertSame([3], $this->numbers($this->data($this->get(\sprintf('/%s/%s/peers', $type, $this->id($type, 2))))));
        self::assertSame([1], $this->numbers($this->data($this->get(\sprintf('/%s/%s/followers', $type, $this->id($type, 2))))));
    }

    #[Test]
    #[DataProvider('types')]
    public function anIncludeReadsEachSide(string $type): void
    {
        $document = $this->get(\sprintf('/%s/%s?include=peers,followers', $type, $this->id($type, 2)));

        self::assertSame([3], $this->numbers($this->relationship($document['data'] ?? null, 'peers')));
        self::assertSame([1], $this->numbers($this->relationship($document['data'] ?? null, 'followers')));
        self::assertSame([1, 3], $this->numbers($this->data(['data' => $document['included'] ?? null])));
    }

    #[Test]
    #[DataProvider('types')]
    public function anIncludeOverAPageOfOwnersGivesEachItsOwnMembers(string $type): void
    {
        $peers = [];
        foreach ($this->data($this->get(\sprintf('/%s?include=peers', $type))) as $owner) {
            $peers[StFixtures::number($this->wireNumber($type, $owner))] = $this->numbers($this->relationship($owner, 'peers'));
        }
        \ksort($peers);

        self::assertSame([1 => [2], 2 => [3], 3 => []], $peers);
    }

    #[Test]
    #[DataProvider('types')]
    public function aNestedIncludeReadsEachLevelFromItsOwnParent(string $type): void
    {
        $document = $this->get(\sprintf('/%s/%s?include=peers.peers', $type, $this->id($type, 1)));

        $included = $this->data(['data' => $document['included'] ?? null]);
        $second = \array_values(\array_filter($included, fn(array $owner): bool => StFixtures::number($this->wireNumber($type, $owner)) === 2));

        self::assertSame([2], $this->numbers($this->relationship($document['data'] ?? null, 'peers')));
        self::assertSame([2, 3], $this->numbers($included));
        self::assertCount(1, $second);
        self::assertSame([3], $this->numbers($this->relationship($second[0], 'peers')));
    }

    #[Test]
    #[DataProvider('types')]
    public function aRelationDeclaredWithDataRendersItsOwnSide(string $type): void
    {
        $document = $this->get(\sprintf('/%s/%s', $type, $this->id($type, 2)));

        self::assertSame([1], $this->numbers($this->relationship($document['data'] ?? null, 'followers')));
    }

    #[Test]
    #[DataProvider('types')]
    public function eachSideCountsItsOwnMembers(string $type): void
    {
        $counts = [];
        foreach ($this->data($this->get(\sprintf('/%s?withCount=peers,followers', $type), self::COUNTS_ACCEPT)) as $owner) {
            $relationships = $owner['relationships'] ?? null;
            self::assertIsArray($relationships);
            $counts[StFixtures::number($this->wireNumber($type, $owner))] = [$this->total($relationships['peers'] ?? null), $this->total($relationships['followers'] ?? null)];
        }
        \ksort($counts);

        self::assertSame([1 => [1, 0], 2 => [1, 1], 3 => [0, 1]], $counts);
    }

    #[Test]
    #[DataProvider('types')]
    public function aFilterThroughTheRelationMatchesOnItsOwnSide(string $type): void
    {
        self::assertSame([1], $this->numbers($this->data($this->get(\sprintf('/%s?filter[peerName]=Owner 2', $type)))));
        self::assertSame([3], $this->numbers($this->data($this->get(\sprintf('/%s?filter[followerName]=Owner 2', $type)))));
    }

    #[Test]
    #[DataProvider('types')]
    public function addingAMemberSeatsItOnTheParentsSide(string $type): void
    {
        $this->write('POST', $type, 3, [1]);

        self::assertSame([1], $this->stored($type, 3, 'peers'));
        self::assertSame([2], $this->stored($type, 1, 'peers'));
        self::assertSame([3], $this->stored($type, 2, 'peers'));
        self::assertSame([1], $this->linkage($type, 3, 'peers'));
    }

    #[Test]
    #[DataProvider('types')]
    public function addingAMemberAlreadySeatedKeepsOneSeat(string $type): void
    {
        $this->write('POST', $type, 1, [2]);

        self::assertSame([2], $this->stored($type, 1, 'peers'));
        self::assertSame([2], $this->linkage($type, 1, 'peers'));
    }

    #[Test]
    #[DataProvider('types')]
    public function removingAMemberUnseatsOnlyTheParentsRow(string $type): void
    {
        $this->write('DELETE', $type, 2, [3]);

        self::assertSame([], $this->stored($type, 2, 'peers'));
        self::assertSame([2], $this->stored($type, 1, 'peers'));
        self::assertSame([], $this->linkage($type, 2, 'peers'));
        self::assertSame([2], $this->linkage($type, 1, 'peers'));
    }

    #[Test]
    #[DataProvider('types')]
    public function replacingTheMembersReseatsOnlyTheParentsRows(string $type): void
    {
        $this->write('PATCH', $type, 1, [3]);

        self::assertSame([3], $this->stored($type, 1, 'peers'));
        self::assertSame([3], $this->stored($type, 2, 'peers'));
        self::assertSame([3], $this->linkage($type, 1, 'peers'));
        self::assertSame([3], $this->linkage($type, 2, 'peers'));
    }

    #[Test]
    #[DataProvider('types')]
    public function creatingAnOwnerSeatsItsMembersBesideIt(string $type): void
    {
        $response = $this->handle('/' . $type, 'POST', ['data' => [
            'type' => $type,
            'id' => StFixtures::wireId($type, 4),
            'attributes' => ['name' => 'Owner 4'],
            'relationships' => ['peers' => ['data' => [$this->identifier($type, 2)]]],
        ]]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());

        self::assertSame([2], $this->stored($type, 4, 'peers'));
        self::assertSame([2], $this->stored($type, 1, 'peers'));
        self::assertSame([3], $this->stored($type, 2, 'peers'));
        self::assertSame([2], $this->linkage($type, 4, 'peers'));
    }

    /**
     * @param list<int> $members
     */
    protected function write(string $method, string $type, int $n, array $members, ?int $rank = null): void
    {
        $data = \array_map(fn(int $member): array => $this->identifier($type, $member, $rank), $members);
        $response = $this->handle(\sprintf('/%s/%s/relationships/peers', $type, $this->id($type, $n)), $method, ['data' => $data]);

        self::assertLessThan(300, $response->getStatusCode(), (string) $response->getContent());
    }

    /**
     * @return array{type: string, id: string, meta?: array{pivot: array{rank: int}}}
     */
    protected function identifier(string $type, int $n, ?int $rank = null): array
    {
        $identifier = ['type' => $type, 'id' => StFixtures::wireId($type, $n)];

        return $rank === null ? $identifier : $identifier + ['meta' => ['pivot' => ['rank' => $rank]]];
    }

    /**
     * The owner numbers in owner `$n`'s `$relation` linkage.
     *
     * @return list<int>
     */
    protected function linkage(string $type, int $n, string $relation): array
    {
        return $this->numbers($this->data($this->get(\sprintf('/%s/%s/relationships/%s', $type, $this->id($type, $n), $relation))));
    }

    /**
     * @return array<string, mixed>
     */
    protected function get(string $path, string $accept = 'application/vnd.api+json'): array
    {
        $response = $this->handle(\str_replace(' ', '%20', $path), extraServer: ['HTTP_ACCEPT' => $accept]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return $this->decode($response);
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return list<array<string, mixed>>
     */
    protected function data(array $document): array
    {
        $data = $document['data'] ?? null;
        self::assertIsArray($data);
        self::assertTrue(\array_is_list($data));

        /** @var list<array<string, mixed>> $data */
        return $data;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function relationship(mixed $resource, string $relation): array
    {
        self::assertIsArray($resource);
        $relationships = $resource['relationships'] ?? null;
        self::assertIsArray($relationships);
        $relationship = $relationships[$relation] ?? null;
        self::assertIsArray($relationship);

        return $this->data($relationship);
    }

    /**
     * The sorted owner numbers of `$identifiers`.
     *
     * @param list<array<string, mixed>> $identifiers
     *
     * @return list<int>
     */
    protected function numbers(array $identifiers): array
    {
        $numbers = \array_map(static fn(array $identifier): int => StFixtures::number(\is_string($identifier['id'] ?? null) ? $identifier['id'] : ''), $identifiers);
        \sort($numbers);

        return $numbers;
    }

    private function total(mixed $relationship): mixed
    {
        self::assertIsArray($relationship);
        $meta = $relationship['meta'] ?? null;
        self::assertIsArray($meta);

        return $meta['total'] ?? null;
    }

    protected function id(string $type, int $n): string
    {
        return \rawurlencode(StFixtures::wireId($type, $n));
    }

    /**
     * @param array<string, mixed> $resource
     */
    private function wireNumber(string $type, array $resource): string
    {
        self::assertSame($type, $resource['type'] ?? null);
        self::assertIsString($resource['id'] ?? null);

        return $resource['id'];
    }
}
