<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApi\Request\JsonApiRequest;
use haddowg\JsonApi\Resource\Field\MorphToMany;
use haddowg\JsonApiBundle\DataProvider\CollectionCriteria;
use haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineDataProvider;
use haddowg\JsonApiBundle\Tests\Functional\App\IdentifiedMorphMany\MtmDoctrineTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\IdentifiedMorphMany\MtmFixtures;
use haddowg\JsonApiBundle\Tests\Functional\App\IdentifiedMorphMany\MtmUser;
use haddowg\JsonApiBundle\Tests\Functional\App\QueryCountingLogger;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\Test;

class DoctrineIdentifiedMorphToManyTest extends IdentifiedMorphToManyConformanceTestCase
{
    protected static function getKernelClass(): string
    {
        return MtmDoctrineTestKernel::class;
    }

    protected function hides(): bool
    {
        return false;
    }

    protected function afterBoot(): void
    {
        $entityManager = $this->entityManager();
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        foreach (MtmFixtures::graph() as $items) {
            foreach ($items as $item) {
                $entityManager->persist($item);
            }
        }
        $entityManager->flush();
        $entityManager->clear();
    }

    protected function storedCompanies(int $user): array
    {
        $this->entityManager()->clear();

        return $this->entityManager()->find(MtmUser::class, $user)?->companyKeys() ?? [];
    }

    protected function storeCompany(int $user, string $type, int $id): void
    {
        $this->entityManager()->getConnection()->insert('mtm_user_company', ['user_id' => $user, 'companyType' => $type, 'companyId' => $id]);
    }

    #[Test]
    public function theLinkageAdmitsEachGuardedTypeInOneIdOnlyQueryAndLoadsNothingElse(): void
    {
        $logger = $this->logger();
        $logger->reset();

        $this->get('/users');

        self::assertSame([], $this->selectsFrom($logger, 'mtm_advertiser'), \implode("\n", $logger->statements()));
        self::assertSame([], $this->selectsFrom($logger, 'mtm_publisher'), \implode("\n", $logger->statements()));
        $agencies = $this->selectsFrom($logger, 'mtm_agency');
        self::assertCount($this->hides() ? 1 : 0, $agencies, \implode("\n", $logger->statements()));
        foreach ($agencies as $sql) {
            self::assertStringNotContainsString('name', \strtolower(\explode(' from ', \strtolower($sql))[0]), $sql);
        }
    }

    #[Test]
    public function theRelatedPageLoadsOnlyThePagesRows(): void
    {
        $logger = $this->logger();
        $logger->reset();

        // The second page of two is publisher 1 then agency 2, or advertiser 2 once agency 2
        // is hidden.
        $this->get('/users/1/companies?page[number]=2&page[size]=2');

        self::assertCount(1, $this->selectsFrom($logger, 'mtm_publisher'), \implode("\n", $logger->statements()));
        self::assertCount($this->hides() ? 1 : 0, $this->selectsFrom($logger, 'mtm_advertiser'), \implode("\n", $logger->statements()));
        // Agency 2's row on the plain page; the id-only admit probe once agencies are guarded.
        $agencies = $this->selectsFrom($logger, 'mtm_agency');
        self::assertCount(1, $agencies, \implode("\n", $logger->statements()));
        self::assertSame($this->hides(), !\str_contains($agencies[0], 'name'), $agencies[0]);
    }

    #[Test]
    public function theRelationshipPageLoadsNoMemberRows(): void
    {
        $logger = $this->logger();
        $logger->reset();

        $this->get('/users/1/relationships/companies?page[number]=2&page[size]=2');

        self::assertSame([], $this->selectsFrom($logger, 'mtm_advertiser'), \implode("\n", $logger->statements()));
        self::assertSame([], $this->selectsFrom($logger, 'mtm_publisher'), \implode("\n", $logger->statements()));
        // Only the id-only admit probe, once agencies are guarded.
        self::assertCount($this->hides() ? 1 : 0, $this->selectsFrom($logger, 'mtm_agency'), \implode("\n", $logger->statements()));
    }

    #[Test]
    public function aDeclaredRelationPagesItsRowsInOneQueryNarrowedBeforeThePage(): void
    {
        $logger = $this->logger();
        $logger->reset();

        $this->get('/accounts/1/relationships/companies?page[number]=2&page[size]=2');

        $pages = \array_values(\array_filter($this->selectsFrom($logger, 'mtm_user_company'), static fn(string $sql): bool => \str_contains($sql, 'LIMIT')));
        self::assertCount(1, $pages, \implode("\n", $logger->statements()));
        // A guarded type counts only its admitted ids, in the same query.
        self::assertSame($this->hides(), \substr_count($pages[0], ' IN (') === 2, $pages[0]);
    }

    #[Test]
    public function aPageOfParentsReadsItsRowsOnceAndNarrowsWithOneKeyReadAndOneAdmissionPerGuardedType(): void
    {
        $logger = $this->logger();
        $logger->reset();

        $this->get('/accounts');

        $keyReads = \array_values(\array_filter(
            $this->selectsFrom($logger, 'mtm_user_company'),
            // A key read selects the owner, type and id fields, never the row's own id.
            static fn(string $sql): bool => \preg_match('/\.id AS /i', \stristr($sql, ' from ', true) ?: $sql) !== 1,
        ));
        self::assertCount($this->hides() ? 1 : 0, $keyReads, \implode("\n", $logger->statements()));
        // Every parent's link rows in one read, never one parent at a time.
        $rowReads = \array_diff($this->selectsFrom($logger, 'mtm_user_company'), $keyReads);
        self::assertCount(1, $rowReads, \implode("\n", $logger->statements()));
        self::assertStringContainsString(' IN (', \implode('', $rowReads));
        self::assertCount($this->hides() ? 1 : 0, $this->selectsFrom($logger, 'mtm_agency'), \implode("\n", $logger->statements()));
        self::assertSame([], $this->selectsFrom($logger, 'mtm_advertiser'), \implode("\n", $logger->statements()));
        self::assertSame([], $this->selectsFrom($logger, 'mtm_publisher'), \implode("\n", $logger->statements()));
    }

    #[Test]
    public function aPlainPolymorphicToManyIsRefusedPointingAtIdentifiedBy(): void
    {
        $provider = static::getContainer()->get(DoctrineDataProvider::class);
        self::assertInstanceOf(DoctrineDataProvider::class, $provider);
        $user = $this->entityManager()->find(MtmUser::class, 1);
        self::assertNotNull($user);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('identifiedBy()');

        $provider->fetchRelatedCollection(
            'agencies',
            $user,
            MorphToMany::make('companies', ['agencies', 'advertisers'])->build(),
            new CollectionCriteria(new QueryParameters(fields: [], includes: [], sort: [], filter: [], pagination: [])),
            new JsonApiRequest(new ServerRequest('GET', '/users/1/companies')),
        );
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        return $entityManager;
    }

    private function logger(): QueryCountingLogger
    {
        $logger = static::getContainer()->get(QueryCountingLogger::class);
        self::assertInstanceOf(QueryCountingLogger::class, $logger);

        return $logger;
    }

    /**
     * @return list<string>
     */
    private function selectsFrom(QueryCountingLogger $logger, string $table): array
    {
        return \array_values(\array_filter(
            $logger->statements(),
            static fn(string $sql): bool => \str_starts_with(\strtolower(\ltrim($sql)), 'select')
                && \preg_match('/\bfrom ' . \preg_quote($table, '/') . '\b/i', $sql) === 1,
        ));
    }
}
