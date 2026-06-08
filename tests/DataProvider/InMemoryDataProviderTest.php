<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\DataProvider;

use haddowg\JsonApi\Exception\FilterParamUnrecognized;
use haddowg\JsonApi\Exception\SortingUnsupported;
use haddowg\JsonApi\Exception\SortParamUnrecognized;
use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApi\Pagination\OffsetWindow;
use haddowg\JsonApi\Pagination\WindowInterface;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApiBundle\DataProvider\CollectionCriteria;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiBundle\Tests\Functional\App\Article;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class InMemoryDataProviderTest extends TestCase
{
    #[Test]
    public function itSupportsOnlyItsOwnType(): void
    {
        $provider = new InMemoryDataProvider('articles', []);

        self::assertTrue($provider->supports('articles'));
        self::assertFalse($provider->supports('comments'));
    }

    #[Test]
    public function itFetchesOneById(): void
    {
        $one = new \stdClass();
        $provider = new InMemoryDataProvider('articles', ['1' => $one]);

        self::assertSame($one, $provider->fetchOne('articles', '1'));
        self::assertNull($provider->fetchOne('articles', '999'));
    }

    #[Test]
    public function itFetchesTheWholeCollectionWithEmptyCriteria(): void
    {
        $provider = $this->articles();

        $result = $provider->fetchCollection('articles', new CollectionCriteria($this->query()));

        self::assertSame(['1', '2', '3'], $this->ids($result->items));
        self::assertNull($result->total);
    }

    #[Test]
    public function itAppliesDeclaredFilters(): void
    {
        $result = $this->articles()->fetchCollection('articles', new CollectionCriteria(
            $this->query(filter: ['title' => 'Bravo']),
            filters: [Where::make('title')],
        ));

        self::assertSame(['2'], $this->ids($result->items));
    }

    #[Test]
    public function itRejectsAnUndeclaredFilterKey(): void
    {
        $this->expectException(FilterParamUnrecognized::class);

        $this->articles()->fetchCollection('articles', new CollectionCriteria(
            $this->query(filter: ['nope' => 'x']),
            filters: [Where::make('title')],
        ));
    }

    #[Test]
    public function itSortsAscendingAndDescending(): void
    {
        $provider = $this->articles();
        $sorts = [SortByField::make('title')];

        $ascending = $provider->fetchCollection('articles', new CollectionCriteria(
            $this->query(sort: ['title']),
            sorts: $sorts,
        ));
        $descending = $provider->fetchCollection('articles', new CollectionCriteria(
            $this->query(sort: ['-title']),
            sorts: $sorts,
        ));

        self::assertSame(['3', '2', '1'], $this->ids($ascending->items));
        self::assertSame(['1', '2', '3'], $this->ids($descending->items));
    }

    #[Test]
    public function itRejectsAnUndeclaredSortField(): void
    {
        $this->expectException(SortParamUnrecognized::class);

        $this->articles()->fetchCollection('articles', new CollectionCriteria(
            $this->query(sort: ['nope']),
            sorts: [SortByField::make('title')],
        ));
    }

    #[Test]
    public function itRejectsSortingWhenNoSortsAreDeclared(): void
    {
        $this->expectException(SortingUnsupported::class);

        $this->articles()->fetchCollection('articles', new CollectionCriteria(
            $this->query(sort: ['title']),
        ));
    }

    #[Test]
    public function itWindowsTheCollectionAndReportsThePreWindowTotal(): void
    {
        $result = $this->articles()->fetchCollection('articles', new CollectionCriteria(
            $this->query(),
            window: new OffsetWindow(1, 1),
        ));

        self::assertSame(['2'], $this->ids($result->items));
        self::assertSame(3, $result->total);
    }

    #[Test]
    public function itRejectsAWindowShapeItCannotExecute(): void
    {
        $window = new class implements WindowInterface {};

        $this->expectException(\LogicException::class);

        $this->articles()->fetchCollection('articles', new CollectionCriteria(
            $this->query(),
            window: $window,
        ));
    }

    /**
     * @return InMemoryDataProvider<Article>
     */
    private function articles(): InMemoryDataProvider
    {
        return new InMemoryDataProvider('articles', [
            '1' => new Article('1', 'Charlie', 'c'),
            '2' => new Article('2', 'Bravo', 'b'),
            '3' => new Article('3', 'Alpha', 'a'),
        ]);
    }

    /**
     * @param list<string>         $sort
     * @param array<string, mixed> $filter
     */
    private function query(array $sort = [], array $filter = []): QueryParameters
    {
        return new QueryParameters([], [], $sort, $filter, []);
    }

    /**
     * @param iterable<object> $items
     *
     * @return list<string>
     */
    private function ids(iterable $items): array
    {
        $ids = [];
        foreach ($items as $item) {
            self::assertInstanceOf(Article::class, $item);
            $ids[] = $item->id;
        }

        return $ids;
    }
}
