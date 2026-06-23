<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Provider;

use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApi\Pagination\OffsetWindow;
use haddowg\JsonApi\Resource\Filter\InMemory\ArrayFilterHandler;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Sort\InMemory\ArraySortHandler;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApiBundle\DataProvider\AbstractDataProvider;
use haddowg\JsonApiBundle\DataProvider\CollectionCriteria;
use haddowg\JsonApiBundle\DataProvider\CriteriaApplier;
use haddowg\JsonApiBundle\DataProvider\DataProviderInterface;
use haddowg\JsonApiBundle\Examples\MusicCatalog\Model\Country;
use Symfony\Component\Intl\Countries;

/**
 * The reference-data provider: a read-only `countries` source backed by NO
 * database. It sources its rows from `symfony/intl`'s {@see Countries} (id = ISO
 * 3166-1 alpha-2 code, attribute = the localized name) and still serves
 * **filter / sort / pagination over the in-memory list** by reusing the shared
 * {@see CriteriaApplier} and core's reference in-memory filter/sort handlers — so
 * an external/static source is a first-class JSON:API collection, not a special
 * case.
 *
 * Because a resource-less type declares no field inventory, the
 * {@see \haddowg\JsonApiBundle\Operation\CrudOperationHandler} cannot hand this
 * provider a filter/sort vocabulary (that lives on a Resource) — so the provider
 * declares its own (`filter[name]` substring + `sort=name`) and rebuilds the
 * criteria around it, then applies {@see CriteriaApplier} exactly as the Doctrine
 * and in-memory providers do. Pagination is likewise driven from the request's
 * `page[number]`/`page[size]` and executed as an {@see OffsetWindow} array slice,
 * since a resource-less type has no server-default paginator.
 *
 * Countries are reference data — never the target of a relationship — so the
 * relationship / batch / pivot seams of {@see DataProviderInterface} are all the
 * neutral "no relationships" default. Rather than hand-stub all six, it extends
 * {@see AbstractDataProvider} and implements only the three read abstracts
 * ({@see supports()} / {@see fetchOne()} / {@see fetchCollection()}); the inherited
 * defaults serve the empty related collection / batch, the empty count map, the
 * all-match to-one, and the empty pivot.
 *
 * @extends AbstractDataProvider<object>
 */
final class CountryProvider extends AbstractDataProvider
{
    private const string LOCALE = 'en';

    private const int DEFAULT_PER_PAGE = 25;

    private readonly CriteriaApplier $applier;

    private readonly ArrayFilterHandler $filterHandler;

    private readonly ArraySortHandler $sortHandler;

    public function __construct()
    {
        $this->applier = new CriteriaApplier();
        $this->filterHandler = new ArrayFilterHandler();
        $this->sortHandler = new ArraySortHandler();
    }

    public function supports(string $type): bool
    {
        return $type === 'countries';
    }

    public function fetchOne(string $type, string $id): ?object
    {
        $code = \strtoupper($id);
        if (!Countries::exists($code)) {
            return null;
        }

        return new Country($code, Countries::getName($code, self::LOCALE));
    }

    public function fetchCollection(string $type, CollectionCriteria $criteria): CollectionResult
    {
        // Rebuild the criteria around THIS provider's own vocabulary (the handler
        // supplies none for a resource-less type), then run the shared applier.
        $vocabularyCriteria = new CollectionCriteria(
            $criteria->queryParameters,
            [Where::make('name', 'name', 'like')],
            [SortByField::make('name', 'name')],
            $criteria->window,
        );

        /** @var list<object> $items */
        $items = $this->applier->apply(
            $vocabularyCriteria,
            $this->all(),
            $this->filterHandler,
            $this->sortHandler,
        );

        return $this->window($items, $criteria->queryParameters);
    }

    // The relationship / batch / pivot seams use the neutral defaults inherited
    // from AbstractDataProvider: countries are reference data, never the target of
    // a relationship, so an empty related collection / batch, an empty count map,
    // an all-match to-one and an empty pivot are exactly right here.

    /**
     * Every country as a {@see Country}, ordered by ISO code for a deterministic
     * base ordering before any requested sort.
     *
     * @return list<Country>
     */
    private function all(): array
    {
        $countries = [];
        foreach (Countries::getNames(self::LOCALE) as $code => $name) {
            $countries[] = new Country($code, $name);
        }

        return $countries;
    }

    /**
     * Windows the already filtered/sorted list from the request's
     * `page[number]`/`page[size]` as an {@see OffsetWindow} slice; an absent
     * `page` returns the whole (filtered) collection unwindowed.
     *
     * @param list<object> $items
     *
     * @return CollectionResult<object>
     */
    private function window(array $items, QueryParameters $queryParameters): CollectionResult
    {
        $pagination = $queryParameters->pagination;
        if ($pagination === []) {
            return new CollectionResult($items);
        }

        $number = \max(1, $this->intParam($pagination, 'number', 1));
        $size = \max(1, $this->intParam($pagination, 'size', self::DEFAULT_PER_PAGE));
        $window = new OffsetWindow(($number - 1) * $size, $size);

        return new CollectionResult(
            \array_slice($items, $window->offset, $window->limit),
            \count($items),
        );
    }

    /**
     * Reads an integer page parameter, falling back to `$default` for an
     * absent or non-numeric value.
     *
     * @param array<string, mixed> $pagination
     */
    private function intParam(array $pagination, string $key, int $default): int
    {
        $value = $pagination[$key] ?? null;

        return \is_numeric($value) ? (int) $value : $default;
    }
}
