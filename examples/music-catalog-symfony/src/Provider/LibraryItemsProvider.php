<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Provider;

use Doctrine\ORM\EntityManagerInterface;
use haddowg\JsonApi\Pagination\OffsetWindow;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\Filter\InMemory\ArrayFilterHandler;
use haddowg\JsonApi\Resource\Sort\InMemory\ArraySortHandler;
use haddowg\JsonApiBundle\DataProvider\CollectionCriteria;
use haddowg\JsonApiBundle\DataProvider\CollectionResult;
use haddowg\JsonApiBundle\DataProvider\CriteriaApplier;
use haddowg\JsonApiBundle\DataProvider\DataProviderInterface;
use haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineDataProvider;
use haddowg\JsonApiBundle\DataProvider\PivotAwareProviderInterface;
use haddowg\JsonApiBundle\DataProvider\PivotCollectionResult;
use haddowg\JsonApiBundle\Examples\MusicCatalog\Entity\Album;
use haddowg\JsonApiBundle\Examples\MusicCatalog\Entity\Artist;
use haddowg\JsonApiBundle\Examples\MusicCatalog\Entity\Library;
use haddowg\JsonApiBundle\Examples\MusicCatalog\Entity\Track;

/**
 * The polymorphic `MorphToMany` provider (seam 2, the polymorphic half) — the
 * NET-NEW escape hatch the Doctrine reference cannot fill: the
 * {@see DoctrineDataProvider} **throws** on a polymorphic to-many because its
 * members span entity classes and cannot be one scoped query. This provider
 * resolves the mixed `libraries.items` members (Track|Album|Artist) **across their
 * per-type repositories**, so `GET /libraries/{id}/items` (and the linkage endpoint
 * and `?include=items`) returns a mixed collection no single Doctrine query could.
 *
 * It supports **two** types so every entry point sees the same members:
 *  - `libraries` — it delegates the fetch to the Doctrine provider, then **populates
 *    the non-mapped {@see Library::$items}** by resolving the members across
 *    repositories, so the relationship-linkage endpoint and `?include` (which read
 *    the list straight off the parent) render the mixed collection;
 *  - `tracks` — the related-collection dispatch resolves the provider by the
 *    relation's first declared type (`relatedTypes()[0]` === `tracks`), so this
 *    provider answers `fetchRelatedCollection()` for the polymorphic items by
 *    reading the already-populated list off the parent. Primary `tracks` reads are
 *    delegated to Doctrine so `GET /tracks` stays intact under the shared kernel.
 *
 * It **shares the {@see EntityManagerInterface}** with the Doctrine provider, so a
 * resolved member is a *managed* entity — writable on the same kernel. It **reuses
 * {@see CriteriaApplier} + an {@see OffsetWindow}** to page the mixed list where a
 * shared vocabulary exists; a polymorphic to-many carries no shared filter/sort
 * vocabulary, so the criteria here only ever window.
 *
 * Because it supports `tracks`, it also shadows the Doctrine provider for the
 * `playlists.orderedTracks` **pivot** relation (whose related type is `tracks`).
 * Pivot fetching is a Doctrine-association-entity concern, so it implements
 * {@see PivotAwareProviderInterface} by **delegating** every pivot method to the
 * wrapped {@see DoctrineDataProvider} — exactly as it delegates a single-typed
 * `fetchRelatedCollection()` — keeping the pivot path intact under the shared kernel.
 *
 * @implements DataProviderInterface<object>
 * @implements PivotAwareProviderInterface<object>
 */
final class LibraryItemsProvider implements DataProviderInterface, PivotAwareProviderInterface
{
    /**
     * The mixed-member map per library id: the JSON:API type and id of each
     * member, resolved across per-type repositories on read. Mirrors core's
     * in-memory seed (`library->items = [track 1, album 2, artist 1]`), so the two
     * example apps render the same mixed collection.
     *
     * @var array<int|string, list<array{type: non-empty-string, id: string}>>
     */
    private const ITEMS_BY_LIBRARY = [
        '1' => [
            ['type' => 'tracks', 'id' => '1'],
            ['type' => 'albums', 'id' => '2'],
            ['type' => 'artists', 'id' => '1'],
        ],
    ];

    private const string PARENT_TYPE = 'libraries';

    private const string RELATED_TYPE = 'tracks';

    private readonly CriteriaApplier $applier;

    private readonly ArrayFilterHandler $filterHandler;

    private readonly ArraySortHandler $sortHandler;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DoctrineDataProvider $doctrine,
    ) {
        $this->applier = new CriteriaApplier();
        $this->filterHandler = new ArrayFilterHandler();
        $this->sortHandler = new ArraySortHandler();
    }

    public function supports(string $type): bool
    {
        return $type === self::PARENT_TYPE || $type === self::RELATED_TYPE;
    }

    public function fetchOne(string $type, string $id): ?object
    {
        $entity = $this->doctrine->fetchOne($type, $id);

        // On the way out of a library fetch, fill its non-mapped items list so the
        // linkage endpoint and ?include render the mixed members off the parent.
        if ($entity instanceof Library) {
            $entity->items = $this->resolveItems((string) $entity->id);
        }

        return $entity;
    }

    public function fetchCollection(string $type, CollectionCriteria $criteria): CollectionResult
    {
        $result = $this->doctrine->fetchCollection($type, $criteria);

        if ($type === self::PARENT_TYPE) {
            foreach ($result->items as $library) {
                if ($library instanceof Library) {
                    $library->items = $this->resolveItems((string) $library->id);
                }
            }
        }

        return $result;
    }

    public function fetchRelatedCollection(
        string $relatedType,
        object $parent,
        RelationInterface $relation,
        CollectionCriteria $criteria,
        JsonApiRequestInterface $request,
    ): CollectionResult {
        // Only the polymorphic items relation is ours; any single-typed to-many
        // resolved by relatedTypes()[0] === 'tracks' stays with the Doctrine push-down.
        if (!$parent instanceof Library || \count($relation->relatedTypes()) <= 1) {
            return $this->doctrine->fetchRelatedCollection($relatedType, $parent, $relation, $criteria, $request);
        }

        // Read the mixed members off the (already populated) parent via the relation's
        // public accessor — the same path the in-memory provider takes.
        $members = $relation->readValue($parent, $request);
        $members = \is_iterable($members)
            ? (\is_array($members) ? \array_values($members) : \iterator_to_array($members, false))
            : $this->resolveItems((string) $parent->id);

        // No shared filter/sort vocabulary across mixed member types, so the criteria
        // only ever carry a window: apply (a no-op for filters/sorts) then page with
        // an OffsetWindow, exactly like the in-memory provider.
        /** @var list<object> $members */
        $members = $this->applier->apply($criteria, $members, $this->filterHandler, $this->sortHandler);

        $window = $criteria->window;
        if ($window === null) {
            return new CollectionResult($members);
        }

        if (!$window instanceof OffsetWindow) {
            throw new \LogicException(\sprintf(
                'The %s can only execute a %s pagination window; got %s.',
                self::class,
                OffsetWindow::class,
                \get_debug_type($window),
            ));
        }

        return new CollectionResult(
            \array_slice($members, $window->offset, $window->limit),
            \count($members),
        );
    }

    public function fetchRelationshipPivot(string $type, object $parent, RelationInterface $relation): array
    {
        // The polymorphic items relation carries no pivot; any real pivot relation
        // resolves through the Doctrine push-down (which returns [] for a non-pivot).
        return $this->doctrine->fetchRelationshipPivot($type, $parent, $relation);
    }

    public function supportsPivot(string $relatedType, RelationInterface $relation): bool
    {
        return $this->doctrine->supportsPivot($relatedType, $relation);
    }

    public function fetchRelatedPivotCollection(
        string $relatedType,
        object $parent,
        RelationInterface $relation,
        CollectionCriteria $criteria,
        JsonApiRequestInterface $request,
    ): PivotCollectionResult {
        return $this->doctrine->fetchRelatedPivotCollection($relatedType, $parent, $relation, $criteria, $request);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function fetchRelatedPivotMap(
        string $relatedType,
        object $parent,
        RelationInterface $relation,
    ): array {
        return $this->doctrine->fetchRelatedPivotMap($relatedType, $parent, $relation);
    }

    /**
     * Resolves the library's mixed members across per-type repositories, sharing
     * the EntityManager so each row comes back *managed*. An unknown library has no
     * items.
     *
     * @return list<object>
     */
    private function resolveItems(string $libraryId): array
    {
        /** @var array<non-empty-string, class-string> $entityClassByType */
        $entityClassByType = [
            'tracks' => Track::class,
            'albums' => Album::class,
            'artists' => Artist::class,
        ];

        $items = [];
        foreach (self::ITEMS_BY_LIBRARY[$libraryId] ?? [] as $pointer) {
            $entityClass = $entityClassByType[$pointer['type']] ?? null;
            if ($entityClass === null) {
                continue;
            }

            $member = $this->entityManager->getRepository($entityClass)->find($pointer['id']);
            if ($member !== null) {
                $items[] = $member;
            }
        }

        return $items;
    }
}
