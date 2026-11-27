<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApi\Schema\Profile\CountableProfile;
use haddowg\JsonApiBundle\Tests\Functional\App\SelfThrough\StDoctrineTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\SelfThrough\StFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * The self-seating owners on Doctrine, where both relations read one seat entity holding
 * two associations to the owner class, plus the seat's `rank`, which renders and filters as
 * a pivot field.
 */
final class DoctrineSelfThroughTest extends SelfThroughConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return StDoctrineTestKernel::class;
    }

    protected function afterBoot(): void
    {
        $entityManager = $this->entityManager();
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        foreach (\array_keys(StFixtures::TYPES) as $type) {
            foreach (StFixtures::owners($type, true) as $owner) {
                $entityManager->persist($owner);
            }
        }
        $entityManager->flush();
        $entityManager->clear();
    }

    #[Test]
    #[DataProvider('types')]
    public function eachSideRendersTheRankOfItsOwnSeat(string $type): void
    {
        self::assertSame([StFixtures::renderedId($type, 3) => ['rank' => 2]], $this->pivots(\sprintf('/%s/%s/relationships/peers', $type, $this->id($type, 2))));
        self::assertSame([StFixtures::renderedId($type, 1) => ['rank' => 1]], $this->pivots(\sprintf('/%s/%s/relationships/followers', $type, $this->id($type, 2))));
        self::assertSame([StFixtures::renderedId($type, 3) => ['rank' => 2]], $this->pivots(\sprintf('/%s/%s/peers', $type, $this->id($type, 2))));
    }

    #[Test]
    #[DataProvider('types')]
    public function aNestedIncludeRendersTheRankOfEachLevelsSeat(string $type): void
    {
        $document = $this->get(\sprintf('/%s/%s?include=peers.peers', $type, $this->id($type, 1)));

        $ranks = [];
        foreach ($this->relationship($document['data'] ?? null, 'peers') as $member) {
            $ranks[] = $member['meta'] ?? null;
        }
        foreach ($this->data(['data' => $document['included'] ?? null]) as $owner) {
            $relationships = $owner['relationships'] ?? null;
            if (\is_array($relationships) && \is_array($relationships['peers'] ?? null) && \array_key_exists('data', $relationships['peers'])) {
                foreach ($this->relationship($owner, 'peers') as $member) {
                    $ranks[] = $member['meta'] ?? null;
                }
            }
        }

        self::assertSame([['pivot' => ['rank' => 1]], ['pivot' => ['rank' => 2]]], $ranks);
    }

    #[Test]
    #[DataProvider('types')]
    public function aPivotFilterReadsTheParentsOwnSeats(string $type): void
    {
        self::assertSame([3], $this->numbers($this->data($this->get(\sprintf('/%s/%s/peers?filter[rank]=2', $type, $this->id($type, 2))))));
        self::assertSame([], $this->numbers($this->data($this->get(\sprintf('/%s/%s/peers?filter[rank]=1', $type, $this->id($type, 2))))));
        self::assertSame([1], $this->numbers($this->data($this->get(\sprintf('/%s/%s/followers?filter[rank]=1', $type, $this->id($type, 2))))));
    }

    #[Test]
    #[DataProvider('types')]
    public function aPathThroughTheSeatReachesEachOfItsSides(string $type): void
    {
        $peers = \sprintf('/%s/%s/peers', $type, $this->id($type, 2));
        $followers = \sprintf('/%s/%s/followers', $type, $this->id($type, 2));

        self::assertSame([3], $this->numbers($this->data($this->get($peers . '?filter[seatedBy]=' . $this->id($type, 2)))));
        self::assertSame([], $this->numbers($this->data($this->get($peers . '?filter[seatedBy]=' . $this->id($type, 3)))));
        self::assertSame([3], $this->numbers($this->data($this->get($peers . '?filter[seating]=' . $this->id($type, 3)))));
        self::assertSame([1], $this->numbers($this->data($this->get($followers . '?filter[seatedBy]=' . $this->id($type, 2)))));
        self::assertSame([1], $this->numbers($this->data($this->get($followers . '?filter[seating]=' . $this->id($type, 1)))));
        self::assertSame([], $this->numbers($this->data($this->get($followers . '?filter[seating]=' . $this->id($type, 2)))));
    }

    #[Test]
    #[DataProvider('types')]
    public function aWrittenSeatKeepsItsRankOnTheParentsSide(string $type): void
    {
        $this->write('POST', $type, 3, [1], 7);
        $this->write('PATCH', $type, 2, [3], 5);

        self::assertSame([[1, 2, 1], [2, 3, 5], [3, 1, 7]], $this->seats($type));
    }

    #[Test]
    #[DataProvider('types')]
    public function aWriteAnswersWithTheMembersAndRanksOfTheParentsSeats(string $type): void
    {
        $response = $this->handle(\sprintf('/%s/%s/relationships/peers', $type, $this->id($type, 3)), 'POST', ['data' => [$this->identifier($type, 1, 4)]]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $member = ['type' => $type, 'id' => StFixtures::renderedId($type, 1), 'meta' => ['pivot' => ['rank' => 4]]];
        self::assertSame([$member], $this->decode($response)['data'] ?? null);

        $response = $this->handle(\sprintf('/%s/%s/relationships/peers', $type, $this->id($type, 2)), 'DELETE', ['data' => [$this->identifier($type, 3)]]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame([], $this->decode($response)['data'] ?? null);
    }

    #[Test]
    #[DataProvider('types')]
    public function aRelationThroughTheSeatsWithNoPivotFieldReadsTheSeats(string $type): void
    {
        self::assertSame([2], $this->linkage($type, 1, 'mates'));
        self::assertSame([3], $this->numbers($this->data($this->get(\sprintf('/%s/%s/mates', $type, $this->id($type, 2))))));
        self::assertSame([], $this->linkage($type, 3, 'mates'));

        $mates = [];
        foreach ($this->data($this->get(\sprintf('/%s?include=mates&withCount=mates', $type), 'application/vnd.api+json;profile="' . CountableProfile::URI . '"')) as $owner) {
            $relationships = $owner['relationships'] ?? null;
            self::assertIsArray($relationships);
            $mates[StFixtures::number($owner['id'] ?? '')] = [$this->numbers($this->relationship($owner, 'mates')), $this->total($relationships['mates'] ?? null)];
        }
        \ksort($mates);
        self::assertSame([1 => [[2], 1], 2 => [[3], 1], 3 => [[], 0]], $mates);

        self::assertSame([1], $this->numbers($this->data($this->get(\sprintf('/%s?filter[mateName]=Owner 2', $type)))));
    }

    #[Test]
    #[DataProvider('types')]
    public function aWriteThroughTheSeatsWithNoPivotFieldWritesTheParentsSeats(string $type): void
    {
        $path = \sprintf('/%s/%s/relationships/mates', $type, $this->id($type, 3));
        $response = $this->handle($path, 'POST', ['data' => [$this->identifier($type, 1)]]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame([StFixtures::renderedId($type, 1)], \array_column($this->data($this->decode($response)), 'id'));
        self::assertSame([[1, 2, 1], [2, 3, 2], [3, 1, 0]], $this->seats($type));

        $response = $this->handle(\sprintf('/%s/%s/relationships/mates', $type, $this->id($type, 1)), 'PATCH', ['data' => [$this->identifier($type, 3)]]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame([[2, 3, 2], [3, 1, 0], [1, 3, 0]], $this->seats($type));

        $response = $this->handle(\sprintf('/%s/%s/relationships/mates', $type, $this->id($type, 2)), 'DELETE', ['data' => [$this->identifier($type, 3)]]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame([[3, 1, 0], [1, 3, 0]], $this->seats($type));
    }

    #[Test]
    #[DataProvider('types')]
    public function aWriteOnOneSideShowsOnTheOtherSide(string $type): void
    {
        $this->write('POST', $type, 3, [1]);
        self::assertSame([3], $this->linkage($type, 1, 'followers'));

        $this->write('DELETE', $type, 2, [3]);
        self::assertSame([], $this->linkage($type, 3, 'followers'));
        self::assertSame([1], $this->linkage($type, 2, 'followers'));

        $this->write('PATCH', $type, 1, [3]);
        self::assertSame([], $this->linkage($type, 2, 'followers'));
        self::assertSame([1], $this->linkage($type, 3, 'followers'));
        self::assertSame([3], $this->linkage($type, 1, 'followers'));
    }

    protected function stored(string $type, int $n, string $relation): array
    {
        $members = [];
        foreach ($this->seats($type) as [$owner, $peer]) {
            if ($relation === 'peers' && $owner === $n) {
                $members[] = $peer;
            } elseif ($relation === 'followers' && $peer === $n) {
                $members[] = $owner;
            }
        }
        \sort($members);

        return $members;
    }

    /**
     * The stored seats of `$type` as owner, peer and rank, read straight from the table.
     *
     * @return list<array{int, int, int}>
     */
    private function seats(string $type): array
    {
        $entityManager = $this->entityManager();
        $entityManager->clear();

        $seats = [];
        foreach ($entityManager->getRepository(StFixtures::TYPES[$type][1])->findBy([], ['id' => 'ASC']) as $seat) {
            \assert(\property_exists($seat, 'owner') && \property_exists($seat, 'peer') && \property_exists($seat, 'rank'));
            \assert(\is_object($seat->owner) && \property_exists($seat->owner, 'id') && \is_object($seat->peer) && \property_exists($seat->peer, 'id'));
            $seats[] = [StFixtures::number($seat->owner->id), StFixtures::number($seat->peer->id), \is_int($seat->rank) ? $seat->rank : -1];
        }

        return $seats;
    }

    /**
     * @return array<string, mixed>
     */
    private function pivots(string $path): array
    {
        $pivots = [];
        foreach ($this->data($this->get($path)) as $member) {
            self::assertIsString($member['id'] ?? null);
            self::assertIsArray($member['meta'] ?? null);
            $pivots[$member['id']] = $member['meta']['pivot'] ?? null;
        }

        return $pivots;
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        return $entityManager;
    }
}
