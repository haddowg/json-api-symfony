<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\DataProvider\Doctrine\QueryPurpose;
use haddowg\JsonApiBundle\Tests\Functional\App\ArticleFixtures;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\ArticleEntityFactory;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\DoctrineExtensionTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\GuideOnlyArticlesExtension;
use PHPUnit\Framework\Attributes\Test;

/**
 * The {@see \haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineExtensionInterface}
 * contract, end-to-end: the {@see GuideOnlyArticlesExtension} scopes `articles`
 * to `category = 'guide'` (ids 1, 2, 4 of the canonical five), and the suite
 * asserts the scope holds on every query the provider builds — the collection,
 * its composition with requested filters, the pre-window COUNT a paginated
 * fetch totals from, and the single fetch (an out-of-scope id is a `404`,
 * which is what the write phase's target loads will rely on).
 */
final class DoctrineExtensionTest extends JsonApiFunctionalTestCase
{
    protected static function getKernelClass(): string
    {
        return DoctrineExtensionTestKernel::class;
    }

    protected function afterBoot(): void
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        // The in-memory SQLite database is empty per connection: create the
        // schema, then seed the canonical rows through the Foundry factory.
        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        ArticleEntityFactory::createSequence(
            \array_map(
                static fn(int|string $id, array $article): array => ['id' => (string) $id, ...$article],
                \array_keys(ArticleFixtures::data()),
                \array_values(ArticleFixtures::data()),
            ),
        );

        $entityManager->clear();
    }

    #[Test]
    public function theCollectionIsScopedBeforeTheRequestedCriteria(): void
    {
        $document = $this->decode($this->handle('/articles?sort=title'));

        self::assertSame(['1', '2', '4'], $this->ids($document));
        self::assertSame([QueryPurpose::FetchCollection], $this->extension()->applied);
    }

    #[Test]
    public function requestedFiltersComposeOnTopOfTheScope(): void
    {
        // 'e' appears in the titles of 2, 3, 4 and 5 — the scope keeps only
        // the guide rows of that intersection.
        $document = $this->decode($this->handle('/articles?filter[titleContains]=e&sort=title'));

        self::assertSame(['2', '4'], $this->ids($document));
    }

    #[Test]
    public function windowedTotalsCountTheScopedCollection(): void
    {
        $document = $this->decode($this->handle('/articles?sort=title&page[number]=2&page[size]=2'));

        self::assertSame(['4'], $this->ids($document));

        $meta = $document['meta'] ?? null;
        self::assertIsArray($meta);
        $page = $meta['page'] ?? null;
        self::assertIsArray($page);

        self::assertSame(3, $page['total'] ?? null);
        self::assertSame(2, $page['currentPage'] ?? null);
        self::assertSame(2, $page['lastPage'] ?? null);
    }

    #[Test]
    public function aSingleFetchOutsideTheScopeIsNotFound(): void
    {
        // Id 3 exists in the database — `category = 'news'` puts it outside
        // the scope, so the provider yields null and the handler renders 404.
        self::assertSame(404, $this->handle('/articles/3')->getStatusCode());
        self::assertSame(200, $this->handle('/articles/1')->getStatusCode());

        self::assertSame([QueryPurpose::FetchOne, QueryPurpose::FetchOne], $this->extension()->applied);
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return list<string>
     */
    private function ids(array $document): array
    {
        $data = $document['data'] ?? null;
        self::assertIsArray($data);

        $ids = [];
        foreach ($data as $resource) {
            self::assertIsArray($resource);
            $id = $resource['id'] ?? null;
            self::assertIsString($id);
            $ids[] = $id;
        }

        return $ids;
    }

    private function extension(): GuideOnlyArticlesExtension
    {
        $extension = static::getContainer()->get(GuideOnlyArticlesExtension::class);
        \assert($extension instanceof GuideOnlyArticlesExtension);

        return $extension;
    }
}
