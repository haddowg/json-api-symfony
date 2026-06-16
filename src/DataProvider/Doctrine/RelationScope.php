<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use haddowg\JsonApi\Resource\Field\RelationInterface;

/**
 * Scopes a related-to-many query — already rooted on the related entity — to a
 * single parent, the parent-membership predicate the related-collection fetch
 * (and, later, the related count/batch) shares.
 *
 * The related entity stays the OUTER query root, so the shared
 * filter/sort/count/window machinery applies identically regardless of which
 * scoping branch is taken:
 *
 *  - **Fast-path** — a single-valued inverse association (the `OneToMany` case,
 *    whose related entity carries the owning foreign key) is scoped directly by
 *    that FK (`related.<owningField> = :jsonapi_parent`).
 *  - **Subquery** — any other to-many (owning-side, or many-to-many on either
 *    side) is scoped by an `IN` subquery rooted on the parent that selects the
 *    related ids it is joined to; the parameter binds on the OUTER builder (the
 *    one that executes).
 *
 * This is a stateless collaborator: the parent's proxy-safe class and the
 * single-valued-association guard are resolved from the injected
 * {@see EntityManagerInterface} per call.
 */
final class RelationScope
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /**
     * Applies the parent-membership predicate to `$builder` (rooted at
     * `$rootAlias` on the `$relatedClass` related entity), scoping it to
     * `$parent` for `$relation`. The branch selection, WHERE clause(s), subquery
     * DQL, and parameter binding mirror the related-collection fetch exactly.
     *
     * @param class-string $relatedClass the related entity rooted at `$rootAlias`
     */
    public function scopeToParent(
        QueryBuilder $builder,
        string $rootAlias,
        string $relatedClass,
        object $parent,
        RelationInterface $relation,
    ): void {
        $property = $relation->column() ?? $relation->name();

        $owningField = $this->inverseOwningField($parent, $property);
        $relatedMetadata = $this->entityManager->getClassMetadata($relatedClass);

        // Fast-path: a single-valued inverse association (the related entity
        // carries the owning FK). A many-to-many *inverse* side also has a
        // non-null mappedBy, but it points to a COLLECTION — the
        // isSingleValuedAssociation guard routes it to the subquery instead.
        if ($owningField !== null && $relatedMetadata->isSingleValuedAssociation($owningField)) {
            $builder
                ->andWhere(\sprintf('%s.%s = :jsonapi_parent', $rootAlias, $owningField))
                ->setParameter('jsonapi_parent', $parent);

            return;
        }

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
                \sprintf('%s.%s', $rootAlias, $relatedIdField),
                $sub->getDQL(),
            ))
            ->setParameter('jsonapi_parent', $parent); // bind on the OUTER builder, which executes
    }

    /**
     * The batched generalisation of {@see scopeToParent()}: scopes a query to a
     * whole PAGE of parents and PROJECTS the parent discriminator so the caller can
     * partition the flat result by parent in PHP (Approach B, bundle ADR 0061). It
     * returns a {@see BatchScope} carrying the query, the related entity's row alias,
     * and the discriminator's select alias.
     *
     * Two shapes, mirroring {@see scopeToParent()}'s two branches:
     *
     *  - **Inverse-FK** — a single-valued inverse association (the `OneToMany` case,
     *    whose related entity carries the owning FK): the related entity stays the
     *    query root and is scoped by `related.<owningField> IN (:ids)`, with the FK
     *    projected as the discriminator (`IDENTITY(related.<owningField>)`). The
     *    related row is the hydrated root.
     *  - **Parent-rooted join** — any other to-many (owning-side, or many-to-many on
     *    either side): the PARENT is the query root, the related collection is joined,
     *    and the page is scoped `WHERE parent IN (:parents)` with the parent id
     *    projected as the discriminator (mirroring {@see DoctrineDataProvider::countRelated()}'s
     *    parent-rooted join). The related row rides each result row under its join alias.
     *
     * Either way the related entity is reachable under {@see BatchScope::$relatedAlias}
     * so the shared {@see \haddowg\JsonApiBundle\DataProvider\CriteriaApplier} applies
     * the related vocabulary on it (the caller passes that alias as the default), and
     * the discriminator is selected as {@see BatchScope::$discriminatorAlias} so the
     * caller groups rows by parent.
     *
     * @param class-string $relatedClass the related entity class
     * @param list<object> $parents      the page of parents to scope to (non-empty)
     */
    public function scopeBatchToParents(
        string $relatedClass,
        array $parents,
        RelationInterface $relation,
    ): BatchScope {
        $property = $relation->column() ?? $relation->name();
        $owningField = $this->inverseOwningField($parents[0], $property);
        $relatedMetadata = $this->entityManager->getClassMetadata($relatedClass);

        // Inverse-FK fast-path: the related entity is the root, scoped directly by the
        // owning FK IN the page, with the FK projected as the parent discriminator. Each
        // row hydrates `[0 => relatedEntity, 'jsonapi_parent_id' => parentStorageId]`.
        if ($owningField !== null && $relatedMetadata->isSingleValuedAssociation($owningField)) {
            $builder = $this->entityManager
                ->getRepository($relatedClass)
                ->createQueryBuilder('related')
                ->addSelect(\sprintf('IDENTITY(related.%s) AS %s', $owningField, BatchScope::PARENT_DISCRIMINATOR_ALIAS))
                ->andWhere(\sprintf('related.%s IN (:jsonapi_parents)', $owningField))
                ->setParameter('jsonapi_parents', $parents);

            return new BatchScope($builder, 'related');
        }

        // Owning-side / many-to-many: a single related-rooted query cannot work, because
        // the same related entity is a member of more than one parent (an author edits two
        // articles) and ORM object hydration DEDUPLICATES a root entity across result rows
        // — collapsing the shared member to one (parent, related) pair and losing whole
        // parents' partitions; and a parent-rooted fetch-join with a related WHERE/ORDER BY
        // hydrates a PARTIAL collection Doctrine then silently re-loads in full on access
        // (the documented fetch-join-filter caveat). So this is the "pair" strategy: root
        // on the PARENT, join the related collection, and SELECT only the scalar (parentId,
        // relatedId) PAIRS — scalars never dedup, so the filtered/ordered membership is
        // exact. The provider then loads the distinct related entities by id in ONE further
        // IN-query and re-associates them per pair, preserving the query's order — two
        // scalar+load queries, still O(N) per relation. The criteria (including the related
        // resource's default sort) apply on the `related` join alias the caller passes as
        // the default alias.
        $parentClass = $this->entityManager->getClassMetadata($parents[0]::class)->getName();
        $parentIdField = $this->entityManager->getClassMetadata($parentClass)->getSingleIdentifierFieldName();
        $relatedIdField = $relatedMetadata->getSingleIdentifierFieldName();

        $builder = $this->entityManager->createQueryBuilder()
            ->select(\sprintf('parent.%s AS %s', $parentIdField, BatchScope::PARENT_DISCRIMINATOR_ALIAS))
            ->addSelect(\sprintf('related.%s AS %s', $relatedIdField, BatchScope::RELATED_DISCRIMINATOR_ALIAS))
            ->from($parentClass, 'parent')
            ->innerJoin(\sprintf('parent.%s', $property), 'related')
            ->where('parent IN (:jsonapi_parents)')
            ->setParameter('jsonapi_parents', $parents);

        return new BatchScope($builder, 'related', relatedClass: $relatedClass, relatedIdField: $relatedIdField);
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
}
