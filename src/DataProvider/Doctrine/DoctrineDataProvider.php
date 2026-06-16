<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use haddowg\JsonApi\Pagination\OffsetWindow;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApiBundle\DataProvider\CollectionCriteria;
use haddowg\JsonApiBundle\DataProvider\CollectionResult;
use haddowg\JsonApiBundle\DataProvider\CriteriaApplier;
use haddowg\JsonApiBundle\DataProvider\DataProviderInterface;

/**
 * The reference Doctrine ORM read provider, wired only when `doctrine/orm` is
 * installed **and** at least one resource maps an entity (the
 * {@see \haddowg\JsonApiBundle\DependencyInjection\Compiler\DoctrineEntityMapPass}
 * removes the service otherwise), because Doctrine is a `require-dev` +
 * `suggest` dependency, not a hard one.
 *
 * A collection fetch is one `QueryBuilder` pipeline: every supporting
 * {@see DoctrineExtensionInterface} customizes the builder first (base
 * constraints, query shaping), then the shared {@see CriteriaApplier} matches
 * the requested `filter[…]`/`sort` parameters against the declared
 * vocabularies and pushes each down through the
 * {@see DoctrineFilterHandler}/{@see DoctrineSortHandler}; a windowed fetch
 * then runs a `COUNT` over the filtered (un-ordered, un-windowed) query before
 * applying the window as `LIMIT`/`OFFSET` — items are never over-fetched.
 * Single fetches run through the same extension pipeline (so a scope holds for
 * `GET /{type}/{id}` too), falling back to `find()` — and its identity-map
 * fast path — only when no extension supports the type.
 *
 * The `type → entity-class` map is populated by the
 * {@see \haddowg\JsonApiBundle\DependencyInjection\Compiler\DoctrineEntityMapPass}
 * from each resource's `#[AsJsonApiResource(entity: …)]` declaration.
 *
 * One instance serves every entity-mapped type — a different entity class per
 * type — so `TEntity` cannot narrow past `object`.
 *
 * @implements DataProviderInterface<object>
 */
final class DoctrineDataProvider implements DataProviderInterface
{
    /**
     * The root alias every generated QueryBuilder uses; handlers re-read it
     * from the builder, so this is a naming choice, not a contract.
     */
    private const string ROOT_ALIAS = 'resource';

    private readonly CriteriaApplier $applier;

    private readonly DoctrineFilterHandler $filterHandler;

    private readonly DoctrineSortHandler $sortHandler;

    /**
     * @var list<DoctrineExtensionInterface>
     */
    private readonly array $extensions;

    /**
     * @param array<string, class-string>          $entityClassByType a `type → entity FQCN` map
     * @param iterable<DoctrineExtensionInterface> $extensions        in descending tag-priority order
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly array $entityClassByType,
        iterable $extensions = [],
    ) {
        $this->extensions = \is_array($extensions) ? \array_values($extensions) : \iterator_to_array($extensions, false);
        $this->applier = new CriteriaApplier();
        $this->filterHandler = new DoctrineFilterHandler();
        $this->sortHandler = new DoctrineSortHandler();
    }

    public function supports(string $type): bool
    {
        return isset($this->entityClassByType[$type]);
    }

    public function fetchOne(string $type, string $id): ?object
    {
        $entityClass = $this->entityClassFor($type);

        $extensions = $this->extensionsFor($type);
        if ($extensions === []) {
            return $this->entityManager->find($entityClass, $id);
        }

        $builder = $this->entityManager
            ->getRepository($entityClass)
            ->createQueryBuilder(self::ROOT_ALIAS);

        // String-id lookups imply a single-field identifier (composite ids
        // cannot round-trip through one JSON:API id segment anyway).
        $idField = $this->entityManager->getClassMetadata($entityClass)->getSingleIdentifierFieldName();
        $builder
            ->andWhere(\sprintf('%s.%s = :jsonapi_id', self::ROOT_ALIAS, $idField))
            ->setParameter('jsonapi_id', $id);

        foreach ($extensions as $extension) {
            $builder = $extension->apply($builder, $type, QueryPurpose::FetchOne);
        }

        $result = $builder->getQuery()->getOneOrNullResult();

        return \is_object($result) ? $result : null;
    }

    public function fetchCollection(string $type, CollectionCriteria $criteria): CollectionResult
    {
        $builder = $this->entityManager
            ->getRepository($this->entityClassFor($type))
            ->createQueryBuilder(self::ROOT_ALIAS);

        foreach ($this->extensionsFor($type) as $extension) {
            $builder = $extension->apply($builder, $type, QueryPurpose::FetchCollection);
        }

        $builder = $this->applier->apply($criteria, $builder, $this->filterHandler, $this->sortHandler);

        $window = $criteria->window;
        if ($window === null) {
            return new CollectionResult($this->items($builder));
        }

        if (!$window instanceof OffsetWindow) {
            throw new \LogicException(\sprintf(
                'The %s can only execute a %s pagination window; got %s.',
                self::class,
                OffsetWindow::class,
                \get_debug_type($window),
            ));
        }

        $total = $this->count($builder);
        $builder->setFirstResult($window->offset)->setMaxResults($window->limit);

        return new CollectionResult($this->items($builder), $total);
    }

    /**
     * Scopes a related to-many to its parent. A single-valued inverse
     * association (the OneToMany case, whose related entity carries the owning
     * foreign key) is scoped by that FK as a fast-path; any other to-many
     * (owning-side, or many-to-many on either side) is scoped by an `IN`
     * subquery rooted on the parent — the related entity stays the OUTER query
     * root, so the shared filter/sort/count/window machinery applies
     * identically to both branches. `$request` is unused (Doctrine push-down).
     *
     * A polymorphic to-many ({@see \haddowg\JsonApi\Resource\Field\MorphToMany},
     * `relatedTypes()` of more than one type) is a deliberate boundary — like the
     * many-to-many subquery scope above, this provider executes one scoped query
     * against a single related entity class, and a polymorphic collection's members
     * span entity classes, so there is no single query to run. It throws; a host
     * that needs it supplies a custom {@see DataProviderInterface} that resolves the
     * related members across types (ADR 0032).
     */
    public function fetchRelatedCollection(
        string $relatedType,
        object $parent,
        RelationInterface $relation,
        CollectionCriteria $criteria,
        JsonApiRequestInterface $request,
    ): CollectionResult {
        if (\count($relation->relatedTypes()) > 1) {
            throw new \LogicException(\sprintf(
                'The %s does not support a polymorphic (morph-to-many) related collection for relationship "%s": its members span entity classes and cannot be one scoped query. Supply a custom DataProvider that resolves the related members across types.',
                self::class,
                $relation->name(),
            ));
        }

        $property = $relation->column() ?? $relation->name();
        $relatedClass = $this->entityClassFor($relatedType);

        $builder = $this->entityManager
            ->getRepository($relatedClass)
            ->createQueryBuilder(self::ROOT_ALIAS);

        $owningField = $this->inverseOwningField($parent, $property);
        $relatedMetadata = $this->entityManager->getClassMetadata($relatedClass);

        // Fast-path: a single-valued inverse association (the related entity
        // carries the owning FK). A many-to-many *inverse* side also has a
        // non-null mappedBy, but it points to a COLLECTION — the
        // isSingleValuedAssociation guard routes it to the subquery instead.
        if ($owningField !== null && $relatedMetadata->isSingleValuedAssociation($owningField)) {
            $builder
                ->andWhere(\sprintf('%s.%s = :jsonapi_parent', self::ROOT_ALIAS, $owningField))
                ->setParameter('jsonapi_parent', $parent);
        } else {
            // Subquery branch (owning-side, or many-to-many either side): scope
            // by membership with an IN subquery that keeps the related entity as
            // the outer query root. getClassMetadata resolves a proxy class to
            // its real entity name.
            $parentClass = $this->entityManager->getClassMetadata($parent::class)->getName();
            $relatedIdField = $relatedMetadata->getSingleIdentifierFieldName();

            $sub = $this->entityManager->createQueryBuilder()
                ->select(\sprintf('related_scope.%s', $relatedIdField))
                ->from($parentClass, 'parent_scope')
                ->innerJoin(\sprintf('parent_scope.%s', $property), 'related_scope')
                ->where('parent_scope = :jsonapi_parent');

            $builder
                ->andWhere($builder->expr()->in(
                    \sprintf('%s.%s', self::ROOT_ALIAS, $relatedIdField),
                    $sub->getDQL(),
                ))
                ->setParameter('jsonapi_parent', $parent); // bind on the OUTER builder, which executes
        }

        foreach ($this->extensionsFor($relatedType) as $extension) {
            $builder = $extension->apply($builder, $relatedType, QueryPurpose::FetchCollection);
        }

        $builder = $this->applier->apply($criteria, $builder, $this->filterHandler, $this->sortHandler);

        $window = $criteria->window;
        if ($window === null) {
            return new CollectionResult($this->items($builder));
        }

        if (!$window instanceof OffsetWindow) {
            throw new \LogicException(\sprintf(
                'The %s can only execute a %s pagination window; got %s.',
                self::class,
                OffsetWindow::class,
                \get_debug_type($window),
            ));
        }

        $total = $this->count($builder);
        $builder->setFirstResult($window->offset)->setMaxResults($window->limit);

        return new CollectionResult($this->items($builder), $total);
    }

    /**
     * @return list<object>
     */
    private function items(QueryBuilder $builder): array
    {
        /** @var list<object> */
        return $builder->getQuery()->getResult();
    }

    /**
     * The total of the filtered collection: the same builder re-selected as a
     * `COUNT` of the root entity, with ordering dropped (it cannot change the
     * count) and no window applied.
     */
    private function count(QueryBuilder $builder): int
    {
        $counter = clone $builder;
        $counter->resetDQLPart('orderBy');
        $counter->select(\sprintf('COUNT(%s)', self::ROOT_ALIAS));

        $total = $counter->getQuery()->getSingleScalarResult();

        return \is_numeric($total) ? (int) $total : 0;
    }

    /**
     * The owning-side field on the related entity for an inverse-side association
     * on the parent (the single-valued FK an inverse OneToMany is `mappedBy`), or
     * `null` when the parent's association is itself the owning side (or
     * many-to-many — no single-valued inverse FK on the related entity to scope
     * by). Mirrors the persister's resolver of the same name.
     */
    private function inverseOwningField(object $parent, string $property): ?string
    {
        $metadata = $this->entityManager->getClassMetadata($parent::class);
        if (!$metadata->hasAssociation($property)) {
            return null;
        }

        $mapping = $metadata->getAssociationMapping($property);

        if ($mapping->isOwningSide()) {
            return null;
        }

        // `mappedBy` lives on the inverse-side mapping; read it through the
        // mapping's array access so the lookup is robust across the ORM 3 mapping
        // class hierarchy.
        $mappedBy = $mapping['mappedBy'] ?? null;

        return \is_string($mappedBy) ? $mappedBy : null;
    }

    /**
     * @return class-string
     */
    private function entityClassFor(string $type): string
    {
        return $this->entityClassByType[$type]
            ?? throw new \LogicException(\sprintf('No Doctrine entity class is mapped for JSON:API type "%s".', $type));
    }

    /**
     * The extensions whose {@see DoctrineExtensionInterface::supports()} is
     * true for `$type`, preserving the injected (priority) order.
     *
     * @return list<DoctrineExtensionInterface>
     */
    private function extensionsFor(string $type): array
    {
        return \array_values(\array_filter(
            $this->extensions,
            static fn(DoctrineExtensionInterface $extension): bool => $extension->supports($type),
        ));
    }
}
