<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\RelationInterface;

/**
 * Resolves the Doctrine association entity backing a `belongsToMany` pivot
 * relation (a {@see BelongsToMany} declaring {@see BelongsToMany::storedPivotFields()}).
 *
 * A plain `#[ORM\ManyToMany]` join table holds only the two foreign keys — it
 * cannot carry a pivot column — so a pivot relation is modelled as an
 * **association entity**: parent `-> OneToMany ->` entity `-> ManyToOne ->` far
 * type. This resolver finds that entity (the {@see PivotAssociation}) two ways:
 *
 * - **`through()` override** (the relation declared
 *   {@see BelongsToMany::pivotThrough()}): the named class is taken verbatim as
 *   the association entity; its two `ManyToOne` sides — the one targeting the
 *   parent and the one targeting the far type — are located on it.
 * - **auto-detection** (the default): scan the parent entity's collection-valued
 *   (to-many) associations for a target entity that ALSO has a single-valued
 *   (`ManyToOne`) association to the related (far) entity. Exactly one such entity
 *   is the pivot; the parent-side property is the parent's to-many target's
 *   inverse `ManyToOne`, the far-side property is that `ManyToOne` to the far
 *   type. Zero matches, or more than one (ambiguous), throws a {@see \LogicException}
 *   naming the relation and pointing at `->through(PivotEntity::class)`.
 *
 * `through(parent:, related:)` names the entity's association on either side, which is
 * checked to be a `ManyToOne` to the right class. A side it leaves out is the entity's
 * one association to that class; an entity holding several associations to the parent
 * class, as a self-referencing one does, may instead take its parent side from the
 * parent's `OneToMany` the relation is stored as, the association that collection is
 * mapped by. Any other ambiguity throws, naming the candidates and the `through()` that
 * settles it, rather than pick one.
 *
 * Resolution is cached per relation name, column, `through()` class and side names,
 * parent class and far class, so a repeated related fetch does not re-scan metadata.
 */
final class PivotAssociationResolver
{
    /**
     * @var array<string, PivotAssociation>
     */
    private array $cache = [];

    public function __construct(private readonly EntityManagerInterface $entityManager) {}

    /**
     * Whether `$relation` is a pivot-backed relation — a {@see BelongsToMany}
     * declaring at least one stored pivot field. A `belongsToMany` without pivot fields,
     * or whose pivot fields are all computed, (or any other to-many) is NOT pivot-backed
     * and keeps the plain related-collection path over its join table.
     */
    public function isPivotRelation(RelationInterface $relation): bool
    {
        return self::readsRows($relation);
    }

    /**
     * Whether `$relation` reads its members through the rows of an association entity: a
     * {@see BelongsToMany} declaring a stored pivot field, or naming the entity with
     * `through()`, as core's relation paths count it.
     */
    public static function readsRows(RelationInterface $relation): bool
    {
        return $relation instanceof BelongsToMany && ($relation->storedPivotFields() !== [] || $relation->pivotThrough() !== null);
    }

    /**
     * Resolves the association entity backing `$relation`, given the loaded parent.
     *
     * @param class-string $farClass the related (far) entity class
     *
     * @throws \LogicException when auto-detection finds no, or an ambiguous, association entity and no `through()` override is declared
     */
    public function resolve(RelationInterface $relation, object $parent, string $farClass): PivotAssociation
    {
        \assert($relation instanceof BelongsToMany);

        $parentClass = $this->entityManager->getClassMetadata($parent::class)->getName();

        return $this->discover($relation, $parentClass, $farClass);
    }

    /**
     * Resolves the association entity backing `$relation` from metadata alone —
     * the parent-instance-free twin of {@see resolve()}. The build-time servability
     * warmer ({@see DoctrineServableWarmer}) drives this at `cache:warmup` so an
     * unresolvable pivot (no `through()` override and no, or an ambiguous,
     * auto-detected association entity) fails the BUILD with the same
     * {@see \LogicException} the first write would otherwise throw, rather than a
     * runtime 500.
     *
     * @param class-string $parentClass the parent (owning) entity class
     * @param class-string $farClass    the related (far) entity class
     *
     * @throws \LogicException when auto-detection finds no, or an ambiguous, association entity and no `through()` override is declared
     */
    public function discover(BelongsToMany $relation, string $parentClass, string $farClass): PivotAssociation
    {
        $cacheKey = \implode("\0", [$relation->name(), $relation->column() ?? '', $relation->pivotThrough() ?? '', $relation->pivotParentSide() ?? '', $relation->pivotRelatedSide() ?? '', $parentClass, $farClass]);

        return $this->cache[$cacheKey] ??= $this->detect($relation, $parentClass, $farClass);
    }

    /**
     * @param class-string $parentClass
     * @param class-string $farClass
     */
    private function detect(BelongsToMany $relation, string $parentClass, string $farClass): PivotAssociation
    {
        $through = $relation->pivotThrough();
        if ($through !== null) {
            return $this->fromThrough($relation, $through, $parentClass, $farClass);
        }

        return $this->autoDetect($relation, $parentClass, $farClass);
    }

    /**
     * Honours the relation's `through()` override: take the class verbatim and find
     * its two `ManyToOne` sides (to the parent, to the far type).
     *
     * @param class-string $parentClass
     * @param class-string $farClass
     */
    private function fromThrough(BelongsToMany $relation, string $through, string $parentClass, string $farClass): PivotAssociation
    {
        if (!\class_exists($through)) {
            throw new \LogicException(\sprintf(
                'The pivot relation "%s" declares through("%s"), but that class does not exist.',
                $relation->name(),
                $through,
            ));
        }

        $metadata = $this->entityManager->getClassMetadata($through);
        $association = $this->sides($relation, $metadata, $parentClass, $farClass);

        if ($association === null) {
            throw new \LogicException(\sprintf(
                'The pivot relation "%s" declares through("%s"), but that entity does not carry a ManyToOne to both the parent (%s) and the related type (%s).',
                $relation->name(),
                $through,
                $parentClass,
                $farClass,
            ));
        }

        return $association;
    }

    /**
     * Scans the parent entity's to-many associations for a target entity that also
     * has a single-valued association to the far type. Exactly one is the pivot.
     *
     * @param class-string $parentClass
     * @param class-string $farClass
     */
    private function autoDetect(BelongsToMany $relation, string $parentClass, string $farClass): PivotAssociation
    {
        $parentMetadata = $this->entityManager->getClassMetadata($parentClass);

        $matches = [];
        $checked = [];
        foreach ($parentMetadata->getAssociationNames() as $field) {
            if (!$parentMetadata->isCollectionValuedAssociation($field)) {
                continue;
            }

            $candidateMetadata = $this->entityManager->getClassMetadata($parentMetadata->getAssociationTargetClass($field));
            if (isset($checked[$candidateMetadata->getName()])) {
                continue;
            }
            $checked[$candidateMetadata->getName()] = true;

            $association = $this->sides($relation, $candidateMetadata, $parentClass, $farClass);
            if ($association !== null) {
                $matches[$association->entityClass] = $association;
            }
        }

        if (\count($matches) === 1) {
            return \array_values($matches)[0];
        }

        if ($matches === []) {
            throw new \LogicException(\sprintf(
                'Could not auto-detect a Doctrine association entity for the pivot relation "%s": no to-many association on %s targets an entity that also has a ManyToOne to %s. Declare it explicitly with ->through(PivotEntity::class).',
                $relation->name(),
                $parentClass,
                $farClass,
            ));
        }

        throw new \LogicException(\sprintf(
            'Auto-detection of the pivot relation "%s" is ambiguous: %d association entities (%s) could back it. Declare the intended one with ->through(PivotEntity::class).',
            $relation->name(),
            \count($matches),
            \implode(', ', \array_keys($matches)),
        ));
    }

    /**
     * The sides of `$metadata` linking the parent to the far type, or `null` when it holds
     * no `ManyToOne` to one of them.
     *
     * A side `through()` names is taken as named, once it is checked to be a `ManyToOne`
     * to the right class. Otherwise the entity's one association to the parent class is
     * the parent side; when it holds several, as a self-referencing one does, the
     * relation's column may name the parent's `OneToMany` to the entity, and the
     * association that collection is mapped by is the parent side. The related side is
     * then the one association to the far type left.
     *
     * @param ClassMetadata<object> $metadata
     * @param class-string          $parentClass
     * @param class-string          $farClass
     *
     * @throws \LogicException when a named side is no such association, or the entity holds several candidates for a side the relation doesn't name
     */
    private function sides(BelongsToMany $relation, ClassMetadata $metadata, string $parentClass, string $farClass): ?PivotAssociation
    {
        $toParent = $this->singleValuedTargeting($metadata, $parentClass);
        $toFar = $this->singleValuedTargeting($metadata, $farClass);
        $namedParent = $relation->pivotParentSide();
        $namedRelated = $relation->pivotRelatedSide();
        $this->assertNamed($relation, $metadata, 'parent', $namedParent, $parentClass, $toParent);
        $this->assertNamed($relation, $metadata, 'related', $namedRelated, $farClass, $toFar);

        $toParent = \array_values(\array_diff($toParent, [$namedRelated]));
        if ($toParent === []) {
            return null;
        }
        $parentProperty = $namedParent ?? (\count($toParent) === 1 ? $toParent[0] : $this->storedSide($relation, $metadata, $parentClass, $toParent));

        $toFar = \array_values(\array_diff($toFar, [$parentProperty]));
        if ($namedRelated === null && \count($toFar) > 1) {
            throw $this->ambiguous($relation, $metadata, 'related', $farClass, $toFar, \sprintf(
                'Name the one that points at the member, such as ->through(%s::class, related: \'%s\').',
                $this->shortName($metadata),
                $toFar[0],
            ));
        }
        $farProperty = $namedRelated ?? $toFar[0] ?? null;
        if ($farProperty === null) {
            return null;
        }

        /** @var class-string $entityClass */
        $entityClass = $metadata->getName();

        return new PivotAssociation($entityClass, $parentProperty, $farProperty);
    }

    /**
     * Checks a side `through()` names is a `ManyToOne` of the entity to its class.
     *
     * @param ClassMetadata<object> $metadata
     * @param list<string>          $candidates the entity's associations to `$class`
     *
     * @throws \LogicException when it is not
     */
    private function assertNamed(BelongsToMany $relation, ClassMetadata $metadata, string $side, ?string $named, string $class, array $candidates): void
    {
        if ($named === null || \in_array($named, $candidates, true)) {
            return;
        }

        throw new \LogicException(\sprintf(
            'The pivot relation "%s" declares through(%s::class, %s: \'%s\'), but %s has no ManyToOne "%s" to %s. %s',
            $relation->name(),
            $this->shortName($metadata),
            $side,
            $named,
            $metadata->getName(),
            $named,
            $class,
            $candidates === []
                ? \sprintf('It has no ManyToOne to %s at all.', $class)
                : \sprintf('Its ManyToOne associations to %s are: %s.', $class, \implode(', ', $candidates)),
        ));
    }

    /**
     * The parent side of an entity holding several associations to the parent class,
     * when the relation is stored as the parent's `OneToMany` mapped by one of them.
     *
     * @param ClassMetadata<object>  $metadata
     * @param class-string           $parentClass
     * @param non-empty-list<string> $toParent
     *
     * @throws \LogicException when the relation is not stored as such a collection
     */
    private function storedSide(BelongsToMany $relation, ClassMetadata $metadata, string $parentClass, array $toParent): string
    {
        $parentMetadata = $this->entityManager->getClassMetadata($parentClass);
        $collection = $relation->column() ?? $relation->name();
        if ($parentMetadata->hasAssociation($collection)
            && $parentMetadata->isCollectionValuedAssociation($collection)
            && $parentMetadata->isAssociationInverseSide($collection)
            && $this->entityManager->getClassMetadata($parentMetadata->getAssociationTargetClass($collection))->getName() === $metadata->getName()) {
            $mappedBy = $parentMetadata->getAssociationMappedByTargetField($collection);
            if (\in_array($mappedBy, $toParent, true)) {
                return $mappedBy;
            }
        }

        throw $this->ambiguous($relation, $metadata, 'parent', $parentClass, $toParent, \sprintf(
            'Name the one that points back at the parent, such as ->through(%s::class, parent: \'%s\').',
            $this->shortName($metadata),
            $toParent[0],
        ));
    }

    /**
     * @param ClassMetadata<object> $metadata
     * @param list<string>          $candidates
     */
    private function ambiguous(BelongsToMany $relation, ClassMetadata $metadata, string $side, string $class, array $candidates, string $fix): \LogicException
    {
        return new \LogicException(\sprintf(
            'The pivot relation "%s" reads %s, which holds several ManyToOne associations that could be its %s side, to %s: %s. %s',
            $relation->name(),
            $metadata->getName(),
            $side,
            $class,
            \implode(', ', $candidates),
            $fix,
        ));
    }

    /**
     * @param ClassMetadata<object> $metadata
     */
    private function shortName(ClassMetadata $metadata): string
    {
        $name = $metadata->getName();
        $at = \strrpos($name, '\\');

        return $at === false ? $name : \substr($name, $at + 1);
    }

    /**
     * The names of the single-valued (`ManyToOne`/`OneToOne`) associations on
     * `$metadata` whose target is `$targetClass`.
     *
     * @param ClassMetadata<object> $metadata
     * @param class-string          $targetClass
     *
     * @return list<string>
     */
    private function singleValuedTargeting(ClassMetadata $metadata, string $targetClass): array
    {
        $targetName = $this->entityManager->getClassMetadata($targetClass)->getName();

        $fields = [];
        foreach ($metadata->getAssociationNames() as $field) {
            if (!$metadata->isSingleValuedAssociation($field)) {
                continue;
            }

            if ($this->entityManager->getClassMetadata($metadata->getAssociationTargetClass($field))->getName() === $targetName) {
                $fields[] = $field;
            }
        }

        return $fields;
    }
}
