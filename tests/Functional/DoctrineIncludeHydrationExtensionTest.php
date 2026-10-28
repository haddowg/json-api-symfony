<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use haddowg\JsonApiBundle\DataProvider\Doctrine\QueryPurpose;
use haddowg\JsonApiBundle\Tests\Functional\App\IncludeHydration\GenreFetchJoinExtension;
use haddowg\JsonApiBundle\Tests\Functional\App\IncludeHydration\HydrationBookEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\IncludeHydration\HydrationGenreEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\IncludeHydration\HydrationShelfEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\IncludeHydration\IncludeHydrationTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\QueryCountingLogger;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The pair-shape include batch hydrates its rows through the related type's
 * extensions under {@see QueryPurpose::IncludeHydration}. A fetch-join extension that
 * opts in loads each included book's genre in the hydration query, where the lazy
 * baseline loads every distinct genre on its own.
 */
final class DoctrineIncludeHydrationExtensionTest extends JsonApiFunctionalTestCase
{
    private const string BASE_URI = 'https://example.test';

    private const string INCLUDE_BOOKS = '/shelves?include=books';

    protected static function getKernelClass(): string
    {
        return IncludeHydrationTestKernel::class;
    }

    protected function afterBoot(): void
    {
        $entityManager = $this->entityManager();
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        $fiction = new HydrationGenreEntity(name: 'Fiction');
        $history = new HydrationGenreEntity(name: 'History');
        $dune = new HydrationBookEntity(title: 'Dune', genre: $fiction);
        $spqr = new HydrationBookEntity(title: 'SPQR', genre: $history);
        $emma = new HydrationBookEntity(title: 'Emma', genre: $fiction);

        $front = new HydrationShelfEntity(name: 'Front');
        $front->books->add($dune);
        $front->books->add($spqr);
        $back = new HydrationShelfEntity(name: 'Back');
        $back->books->add($spqr);
        $back->books->add($emma);
        $empty = new HydrationShelfEntity(name: 'Empty');

        foreach ([$fiction, $history, $dune, $spqr, $emma, $front, $back, $empty] as $entity) {
            $entityManager->persist($entity);
        }
        $entityManager->flush();

        // An empty identity map, so the lazy baseline really loads each genre.
        $entityManager->clear();
    }

    #[Test]
    #[Group('spec:fetching-includes')]
    public function anOptedInFetchJoinLoadsTheIncludedRowsAssociationInTheHydrationQuery(): void
    {
        $this->extension()->enabled = false;
        $baseline = $this->includeBooks();
        $baselineQueries = $this->logger()->queryCount();
        self::assertCount(2, $this->standaloneGenreSelects(), 'the lazy baseline loads each distinct genre on its own');

        $this->extension()->enabled = true;
        $joined = $this->includeBooks();

        self::assertSame([], $this->standaloneGenreSelects(), "the fetch-join loads every genre with the books:\n" . \implode("\n", $this->logger()->statements()));
        self::assertSame($baselineQueries - 2, $this->logger()->queryCount());

        $hydration = \array_values(\array_filter(
            $this->logger()->statements(),
            static fn(string $sql): bool => \str_contains(\strtolower($sql), 'from hydration_book')
                && \str_contains(\strtolower($sql), 'join hydration_genre'),
        ));
        self::assertCount(1, $hydration, 'one hydration query joins the genres');

        self::assertSame($baseline, $joined, 'the fetch-join changes no rendered byte');
        self::assertSame(
            ['1' => 'Fiction', '2' => 'History', '3' => 'Fiction'],
            $this->includedGenreNames($joined),
        );

        self::assertContains(QueryPurpose::IncludeHydration, $this->extension()->applied);
        self::assertContains(QueryPurpose::FetchRelatedCollection, $this->extension()->applied);
    }

    /**
     * @return array<string, mixed>
     */
    private function includeBooks(): array
    {
        $this->entityManager()->clear();
        $this->logger()->reset();

        $response = $this->handle(self::BASE_URI . self::INCLUDE_BOOKS);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return $this->decode($response);
    }

    /**
     * Genre loads that are not part of another table's query.
     *
     * @return list<string>
     */
    private function standaloneGenreSelects(): array
    {
        return \array_values(\array_filter(
            $this->logger()->statements(),
            static fn(string $sql): bool => \str_contains(\strtolower($sql), 'from hydration_genre'),
        ));
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed> book id => rendered genre name
     */
    private function includedGenreNames(array $document): array
    {
        $included = $document['included'] ?? [];
        self::assertIsArray($included);

        $names = [];
        foreach ($included as $resource) {
            self::assertIsArray($resource);
            self::assertSame('books', $resource['type'] ?? null);
            $id = $resource['id'] ?? null;
            self::assertIsString($id);
            $attributes = $resource['attributes'] ?? null;
            self::assertIsArray($attributes);
            $names[$id] = $attributes['genreName'] ?? null;
        }
        \ksort($names);

        return $names;
    }

    private function extension(): GenreFetchJoinExtension
    {
        $extension = static::getContainer()->get(GenreFetchJoinExtension::class);
        \assert($extension instanceof GenreFetchJoinExtension);

        return $extension;
    }

    private function logger(): QueryCountingLogger
    {
        $logger = static::getContainer()->get(QueryCountingLogger::class);
        \assert($logger instanceof QueryCountingLogger);

        return $logger;
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        \assert($entityManager instanceof EntityManagerInterface);

        return $entityManager;
    }
}
