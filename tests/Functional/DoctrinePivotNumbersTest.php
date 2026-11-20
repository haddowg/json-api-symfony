<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\Tests\Functional\App\PivotNumbers\PnBoard;
use haddowg\JsonApiBundle\Tests\Functional\App\PivotNumbers\PnDoctrineTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\PivotNumbers\PnFixtures;
use haddowg\JsonApiBundle\Tests\Functional\App\PivotNumbers\PnPin;
use PHPUnit\Framework\Attributes\Test;

/**
 * The link-row conformance on Doctrine, plus `pins`, a pivot relation over an association
 * entity, which the in-memory store can't serve. Board 1 pins card 1 with a count of 1 and
 * a weight of 1.5.
 */
final class DoctrinePivotNumbersTest extends PivotNumbersConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return PnDoctrineTestKernel::class;
    }

    protected function afterBoot(): void
    {
        $entityManager = $this->entityManager();
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        foreach (PnFixtures::graph(true) as $entities) {
            foreach ($entities as $entity) {
                $entityManager->persist($entity);
            }
        }
        $entityManager->flush();
        $entityManager->clear();
    }

    #[Test]
    public function addingAPivotRowStoresItsNumbersInTheirFieldsTypes(): void
    {
        $response = $this->send('/pn-boards/1/relationships/pins', 'POST', ['data' => [
            ['type' => 'pn-cards', 'id' => '2', 'meta' => ['pivot' => ['count' => 5.0, 'weight' => 5]]],
        ]]);
        self::assertLessThan(300, $response->getStatusCode(), (string) $response->getContent());

        self::assertSame(['1' => [1, 1.5], '2' => [5, 5.0]], $this->pins('1'));
        self::assertSame(['count' => 5, 'weight' => 5.0], $this->renderedPivots('/pn-boards/1/relationships/pins')['2'] ?? null);
    }

    #[Test]
    public function replacingPivotRowsStoresTheirNumbersInTheirFieldsTypes(): void
    {
        $response = $this->send('/pn-boards/1/relationships/pins', 'PATCH', ['data' => [
            ['type' => 'pn-cards', 'id' => '1', 'meta' => ['pivot' => ['count' => 7.0, 'weight' => 2]]],
            ['type' => 'pn-cards', 'id' => '3', 'meta' => ['pivot' => ['count' => 2.0, 'weight' => 3]]],
        ]]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        self::assertSame(['1' => [7, 2.0], '3' => [2, 3.0]], $this->pins('1'));
    }

    #[Test]
    public function aCreatedResourcesPivotRowsStoreTheirNumbersInTheirFieldsTypes(): void
    {
        $response = $this->send('/pn-boards', 'POST', ['data' => [
            'type' => 'pn-boards',
            'id' => '2',
            'attributes' => ['name' => 'Side'],
            'relationships' => ['pins' => ['data' => [
                ['type' => 'pn-cards', 'id' => '2', 'meta' => ['pivot' => ['count' => 4.0, 'weight' => 4]]],
            ]]],
        ]]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());

        self::assertSame(['2' => [4, 4.0]], $this->pins('2'));
    }

    #[Test]
    public function aPivotValueThatIsNoNumberIsA422AtItsPointer(): void
    {
        $this->assertRefusedAt('/data/0/meta/pivot/count', $this->send('/pn-boards/1/relationships/pins', 'POST', ['data' => [
            ['type' => 'pn-cards', 'id' => '2', 'meta' => ['pivot' => ['count' => 'five', 'weight' => 5]]],
        ]]));
        $this->assertRefusedAt('/data/0/meta/pivot/weight', $this->send('/pn-boards/1/relationships/pins', 'PATCH', ['data' => [
            ['type' => 'pn-cards', 'id' => '1', 'meta' => ['pivot' => ['count' => 1, 'weight' => 'heavy']]],
        ]]));
        $this->assertRefusedAt('/data/relationships/pins/data/0/meta/pivot/count', $this->send('/pn-boards', 'POST', ['data' => [
            'type' => 'pn-boards',
            'id' => '2',
            'attributes' => ['name' => 'Side'],
            'relationships' => ['pins' => ['data' => [
                ['type' => 'pn-cards', 'id' => '2', 'meta' => ['pivot' => ['count' => 'four', 'weight' => 4]]],
            ]]],
        ]]));

        self::assertSame(['1' => [1, 1.5]], $this->pins('1'));
    }

    protected function board(string $id): PnBoard
    {
        $entityManager = $this->entityManager();
        $entityManager->clear();
        $board = $entityManager->find(PnBoard::class, $id);
        self::assertInstanceOf(PnBoard::class, $board);

        return $board;
    }

    /**
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    private function pins(string $board): array
    {
        $pins = [];
        foreach ($this->board($board)->pins as $pin) {
            \assert($pin instanceof PnPin && $pin->card !== null);
            $pins[(string) $pin->card->id] = [$pin->count, $pin->weight];
        }
        \ksort($pins);

        return $pins;
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        return $entityManager;
    }
}
