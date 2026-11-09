<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\PrivateCollection\PrivateCollectionTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\PrivateCollection\PrivPlayerEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\PrivateCollection\PrivSquadEntity;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every association property of `squads` and `players` is private with no adder or
 * setter, so the persister writes each relationship through reflection, as Doctrine
 * hydrates it. Doctrine-only: the in-memory persister writes its own plain objects.
 */
#[Group('spec:crud')]
final class DoctrinePrivateCollectionTest extends JsonApiFunctionalTestCase
{
    /**
     * @var list<string> player wire ids, in seed order
     */
    private array $players = [];

    private string $squad = '';

    protected static function getKernelClass(): string
    {
        return PrivateCollectionTestKernel::class;
    }

    protected function afterBoot(): void
    {
        $entityManager = $this->entityManager();
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        $players = [];
        foreach (['Ada', 'Bo', 'Cy'] as $name) {
            $players[] = $player = new PrivPlayerEntity($name);
            $entityManager->persist($player);
        }
        $squad = new PrivSquadEntity();
        $squad->name = 'Reds';
        $entityManager->persist($squad);
        $entityManager->flush();

        $this->players = \array_map(static fn(PrivPlayerEntity $player): string => (string) $player->id, $players);
        $this->squad = (string) $squad->id;
        $entityManager->clear();
    }

    #[Test]
    public function aCreateSetsPrivateRelationships(): void
    {
        $response = $this->handle('/squads', 'POST', ['data' => [
            'type' => 'squads',
            'attributes' => ['name' => 'Blues'],
            'relationships' => [
                'captain' => ['data' => $this->player(0)],
                'players' => ['data' => [$this->player(0), $this->player(1)]],
                'recruits' => ['data' => [$this->player(2)]],
            ],
        ]]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());

        $id = $this->dataOf($response, 201)['id'] ?? null;
        self::assertIsString($id);
        $squad = $this->squadData($id);
        self::assertSame($this->player(0), $this->linkage($squad, 'captain'));
        self::assertSame([$this->player(0), $this->player(1)], $this->linkage($squad, 'players'));
        self::assertSame([$this->player(2)], $this->linkage($squad, 'recruits'));
    }

    #[Test]
    public function aPrivateToManyIsReplacedAddedToAndRemovedFrom(): void
    {
        $path = '/squads/' . $this->squad . '/relationships/players';

        $this->write($path, 'PATCH', [$this->player(0), $this->player(1)]);
        self::assertSame([$this->player(0), $this->player(1)], $this->linkage($this->squadData($this->squad), 'players'));

        $this->write($path, 'POST', [$this->player(2)]);
        $this->write($path, 'DELETE', [$this->player(0)]);
        self::assertSame([$this->player(1), $this->player(2)], $this->linkage($this->squadData($this->squad), 'players'));

        $this->write($path, 'PATCH', []);
        self::assertSame([], $this->linkage($this->squadData($this->squad), 'players'));
    }

    #[Test]
    public function aPrivateToOneIsReplaced(): void
    {
        $path = '/squads/' . $this->squad . '/relationships/captain';

        $this->write($path, 'PATCH', $this->player(1));
        self::assertSame($this->player(1), $this->linkage($this->squadData($this->squad), 'captain'));

        $this->write($path, 'PATCH', null);
        self::assertNull($this->linkage($this->squadData($this->squad), 'captain'));
    }

    #[Test]
    public function aPrivateInverseToManyReassignsAndDetachesItsMembers(): void
    {
        $path = '/squads/' . $this->squad . '/relationships/recruits';

        $this->write($path, 'PATCH', [$this->player(0), $this->player(1)]);
        self::assertSame([$this->player(0), $this->player(1)], $this->linkage($this->squadData($this->squad), 'recruits'));

        $this->write($path, 'PATCH', [$this->player(2)]);
        self::assertSame([$this->player(2)], $this->linkage($this->squadData($this->squad), 'recruits'));
        self::assertNull($this->linkage($this->playerData($this->players[0]), 'squad'));
    }

    /**
     * @return array{type: string, id: string}
     */
    private function player(int $index): array
    {
        return ['type' => 'players', 'id' => $this->players[$index]];
    }

    private function write(string $path, string $method, mixed $data): void
    {
        $response = $this->handle($path, $method, ['data' => $data]);
        self::assertContains($response->getStatusCode(), [200, 204], (string) $response->getContent());
        $this->entityManager()->clear();
    }

    /**
     * @return array<string, mixed>
     */
    private function squadData(string $id): array
    {
        return $this->dataOf($this->handle('/squads/' . $id));
    }

    /**
     * @return array<string, mixed>
     */
    private function playerData(string $id): array
    {
        return $this->dataOf($this->handle('/players/' . $id));
    }

    /**
     * @return array<string, mixed>
     */
    private function dataOf(Response $response, int $status = 200): array
    {
        self::assertSame($status, $response->getStatusCode(), (string) $response->getContent());
        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * @param array<string, mixed> $resource
     */
    private function linkage(array $resource, string $relation): mixed
    {
        $relationships = $resource['relationships'] ?? null;
        self::assertIsArray($relationships);
        $object = $relationships[$relation] ?? null;
        self::assertIsArray($object);
        self::assertArrayHasKey('data', $object);

        return $object['data'];
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        return $entityManager;
    }
}
