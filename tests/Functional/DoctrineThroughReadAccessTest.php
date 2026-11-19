<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\Filter\ThroughPathResolver;
use haddowg\JsonApi\Resource\Filter\WhereThrough;
use haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineFilterHandler;
use haddowg\JsonApiBundle\DataProvider\FilterPaths;
use haddowg\JsonApiBundle\Tests\Functional\App\ThroughAccess\Doctrine\CrateEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\ThroughAccess\Doctrine\DoctrineCrateResource;
use haddowg\JsonApiBundle\Tests\Functional\App\ThroughAccess\Doctrine\DoctrineVaultResource;
use haddowg\JsonApiBundle\Tests\Functional\App\ThroughAccess\Doctrine\ThroughAccessDoctrineKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\ThroughAccess\ThroughFixtures;
use PHPUnit\Framework\Attributes\Test;

/**
 * {@see ThroughReadAccessConformance} on the Doctrine kernel, where query extensions
 * scope the rows each hop of the `EXISTS` reaches.
 */
class DoctrineThroughReadAccessTest extends JsonApiFunctionalTestCase
{
    use ThroughReadAccessConformance;

    protected static function getKernelClass(): string
    {
        return ThroughAccessDoctrineKernel::class;
    }

    protected function afterBoot(): void
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());
        foreach (ThroughFixtures::graph() as $rows) {
            foreach ($rows as $row) {
                $entityManager->persist($row);
            }
        }
        $entityManager->flush();
        $entityManager->clear();
    }

    #[Test]
    public function the_exists_admits_no_row_through_a_refused_relation(): void
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);
        $inventories = ['crates' => (new DoctrineCrateResource())->allFields(), 'vaults' => (new DoctrineVaultResource())->allFields()];
        $paths = new FilterPaths(new ThroughPathResolver(static fn(string $type): ?array => $inventories[$type] ?? null), $inventories['crates'], type: 'crates');
        $filter = $paths->resolve(WhereThrough::make('vaultCode', 'vault.code')->build());
        $vault = $paths->relationAt('vault');
        self::assertInstanceOf(RelationInterface::class, $vault);
        $handler = new DoctrineFilterHandler();

        $ids = static function (FilterPaths $scoped) use ($entityManager, $handler, $filter): array {
            $query = $entityManager->createQueryBuilder()->select('c.id')->from(CrateEntity::class, 'c');
            $handler->withPaths($scoped, static fn(): mixed => $handler->apply($filter, $query, 'v1'));

            return \array_column($query->getQuery()->getArrayResult(), 'id');
        };

        self::assertSame([], $ids($paths->scoped(null, [$vault])));
        self::assertSame([1], $ids($paths->scoped(null)));
    }
}
