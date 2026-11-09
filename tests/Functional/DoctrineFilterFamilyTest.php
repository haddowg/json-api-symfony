<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApi\Resource\Filter\WhereIn;
use haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineFilterHandler;
use haddowg\JsonApiBundle\Tests\Functional\App\FilterFamily\Doctrine\BoardEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\FilterFamily\Doctrine\FilterFamilyDoctrineTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\FilterFamily\Doctrine\ItemEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\FilterFamily\Doctrine\PinEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\FilterFamily\ItemFixtures;
use PHPUnit\Framework\Attributes\Test;

final class DoctrineFilterFamilyTest extends FilterFamilyConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return FilterFamilyDoctrineTestKernel::class;
    }

    protected function pivotAware(): bool
    {
        return true;
    }

    protected function afterBoot(): void
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        $boards = [];
        foreach (ItemFixtures::boards() as $id => $name) {
            $entityManager->persist($boards[$id] = new BoardEntity($id, $name));
        }
        $items = [];
        foreach (ItemFixtures::items() as $id => $row) {
            $entityManager->persist($items[$id] = new ItemEntity($id, $boards[$row['board']], $row['name'], $row['label'], $row['color'], $row['size'], $row['weight']));
        }
        foreach (ItemFixtures::pins() as $board => $pins) {
            foreach ($pins as $item => $pin) {
                $entityManager->persist(new PinEntity(null, $boards[$board], $items[$item], $pin['rank'], new \DateTimeImmutable($pin['pinnedAt'])));
            }
        }
        $entityManager->flush();
        $entityManager->clear();
    }

    #[Test]
    public function theHandlerReadsASetFiltersValueThroughItsList(): void
    {
        // Handed a string directly, an array-only list queries it whole and a delimited
        // one splits it, as core's in-memory handler does.
        self::assertSame([1, 5], $this->handled(WhereIn::make('labels', 'label')->acceptsList(delimited: false)->build(), 'a,b'));
        self::assertSame([1, 3], $this->handled(WhereIn::make('name')->acceptsList()->build(), 'apple, cherry'));
    }

    /**
     * @return list<int>
     */
    private function handled(WhereIn $filter, string $value): array
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        $builder = $entityManager->createQueryBuilder()->select('root.id')->from(ItemEntity::class, 'root')->orderBy('root.id');
        $ids = \array_column((new DoctrineFilterHandler())->apply($filter, $builder, $value)->getQuery()->getScalarResult(), 'id');

        return \array_map(static fn(mixed $id): int => \is_numeric($id) ? (int) $id : 0, $ids);
    }
}
