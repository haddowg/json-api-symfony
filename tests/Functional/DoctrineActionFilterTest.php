<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApiBundle\Action\ActionRegistry;
use haddowg\JsonApiBundle\Action\ActionScope;
use haddowg\JsonApiBundle\DataProvider\CollectionCriteria;
use haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineDataProvider;
use haddowg\JsonApiBundle\DataProvider\Doctrine\ExtensionContext;
use haddowg\JsonApiBundle\DataProvider\Doctrine\QueryPurpose;
use haddowg\JsonApiBundle\Tests\Functional\App\ActionFilter\CrateFactory;
use haddowg\JsonApiBundle\Tests\Functional\App\ActionFilter\Doctrine\ActionFilterDoctrineTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\ActionFilter\Doctrine\CrateEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\ActionFilter\Doctrine\HiddenCratesExtension;
use PHPUnit\Framework\Attributes\Test;

/**
 * The Doctrine kernel adds a seventh, `hidden` open crate, which the
 * {@see HiddenCratesExtension} keeps out of every query.
 */
final class DoctrineActionFilterTest extends ActionFilterConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return ActionFilterDoctrineTestKernel::class;
    }

    protected function afterBoot(): void
    {
        $entityManager = $this->entityManager();
        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        foreach (CrateFactory::rows() as $row) {
            $entityManager->persist(new CrateEntity(null, $row['name'], $row['status'], $row['weight'], $row['lifecycle']));
        }
        $entityManager->persist(new CrateEntity(null, 'eta', 'open', 3, 'live', hidden: true));
        $entityManager->flush();
        $entityManager->clear();

        HiddenCratesExtension::$seen = [];
    }

    #[Test]
    public function theDoctrineProviderOffersAFilteredQueryBuilder(): void
    {
        $meta = $this->meta('/filterCrates/-actions/tally?filter[status]=open');
        self::assertSame(QueryBuilder::class, $meta['native'] ?? null);
        self::assertSame(['1', '3', '6'], $meta['queried'] ?? null);
    }

    #[Test]
    public function theExtensionScopesTheActionsRowsUnderItsOwnPurpose(): void
    {
        self::assertSame(['1', '3', '6'], $this->ids('/filterCrates/-actions/tally?filter[status]=open'));

        $contexts = \array_values(\array_filter(
            HiddenCratesExtension::$seen,
            static fn(ExtensionContext $context): bool => $context->purpose === QueryPurpose::ActionCollection,
        ));
        self::assertNotEmpty($contexts);
        foreach ($contexts as $context) {
            self::assertSame('filterCrates', $context->type);
            self::assertSame('tally', $context->action?->path);
            self::assertNotNull($context->request);
        }
    }

    #[Test]
    public function eachReadsInKeysetBatchesAndClearsTheEntityManagerBetweenThem(): void
    {
        $provider = static::getContainer()->get(DoctrineDataProvider::class);
        self::assertInstanceOf(DoctrineDataProvider::class, $provider);
        $registry = static::getContainer()->get(ActionRegistry::class);
        self::assertInstanceOf(ActionRegistry::class, $registry);
        $action = $registry->descriptorFor('default', 'filterCrates', ActionScope::Collection, 'tally');
        self::assertNotNull($action);

        $criteria = new CollectionCriteria(new QueryParameters([], [], [], [], []));
        $entityManager = $this->entityManager();

        $ids = [];
        $first = null;
        $firstAttachedInFirstBatch = false;
        foreach ($provider->eachFiltered('filterCrates', $criteria, $action, 2) as $crate) {
            self::assertInstanceOf(CrateEntity::class, $crate);
            $ids[] = $crate->id;
            if ($first === null) {
                $first = $crate;
            }
            if (\count($ids) === 2) {
                $firstAttachedInFirstBatch = $entityManager->contains($first);
            }
            if (\count($ids) === 3) {
                self::assertFalse($entityManager->contains($first), 'The first batch is detached once the second is read.');
                self::assertTrue($entityManager->contains($crate));
            }
        }

        // The criteria declares no filters, so no lifecycle default applies and only the
        // extension narrows the rows.
        self::assertSame([1, 2, 3, 4, 5, 6], $ids);
        self::assertTrue($firstAttachedInFirstBatch);
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        return $entityManager;
    }
}
