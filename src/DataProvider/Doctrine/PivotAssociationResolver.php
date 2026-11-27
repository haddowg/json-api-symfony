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
 * An entity holding several associations to the parent class, as a self-referencing
 * one does, takes its parent side from the parent's `OneToMany` the relation is stored
 * as: the association that collection is mapped by. Without one, or with several
 * associations to the far type left once the parent side is taken, the resolution
 * throws rather than pick one.
 *
 * Resolution is cached per relation name, column and `through()` class, parent class and
 * far class, so a repeated related fetch does not re-scan metadata.
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
        return $relation instanceof BelongsToMany && $relation->storedPivotFields() !== [];
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
        $cacheKey = \implode("\0", [$relation->name(), $relation->column() ?? '', $relation->pivotThrough() ?? '', $parentClass, $farClass]);

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
     * The entity's one association to the parent class is the parent side. When it holds
     * several, as a self-referencing entity does, the relation's column names the parent's
     * `OneToMany` to the entity, and the association that collection is mapped by is the
     * parent side. The far side is then the one association to the far type left.
     *
     * @param ClassMetadata<object> $metadata
     * @param class-string          $parentClass
     * @param class-string          $farClass
     *
     * @throws \LogicException when the entity holds several associations to either end and the relation doesn't say which
     */
    private function sides(BelongsToMany $relation, ClassMetadata $metadata, string $parentClass, string $farClass): ?PivotAssociation
    {
        $toParent = $this->singleValuedTargeting($metadata, $parentClass);
        if ($toParent === []) {
            return null;
        }

        $parentProperty = \count($toParent) === 1 ? $toParent[0] : $this->namedParentSide($relation, $metadata, $parentClass, $toParent);
        $toFar = \array_values(\array_diff($this->singleValuedTargeting($metadata, $farClass), [$parentProperty]));
        if ($toFar === []) {
            return null;
        }
        if (\count($toFar) > 1) {
            throw new \LogicException(\sprintf(
                'The pivot relation "%s" reads %s, which holds several ManyToOne associations to the related type %s besides its parent side "%s" (%s), so its related side is ambiguous. Give the entity a single other association to %s.',
                $relation->name(),
                $metadata->getName(),
                $farClass,
                $parentProperty,
                \implode(', ', $toFar),
                $farClass,
            ));
        }

        /** @var class-string $entityClass */
        $entityClass = $metadata->getName();

        return new PivotAssociation($entityClass, $parentProperty, $toFar[0]);
    }

    /**
     * The parent side of an entity holding several associations to the parent class: the
     * one the parent's `OneToMany` the relation is stored as is mapped by.
     *
     * @param ClassMetadata<object> $metadata
     * @param class-string          $parentClass
     * @param non-empty-list<string> $toParent
     *
     * @throws \LogicException when the relation is not stored as such a collection
     */
    private function namedParentSide(BelongsToMany $relation, ClassMetadata $metadata, string $parentClass, array $toParent): string
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

        throw new \LogicException(\sprintf(
            'The pivot relation "%s" reads %s, which holds several ManyToOne associations to the parent %s (%s), so its parent side is ambiguous. Map a OneToMany on %s to %s, mapped by the side that points back to the parent, and store the relation as that collection with ->storedAs().',
            $relation->name(),
            $metadata->getName(),
            $parentClass,
            \implode(', ', $toParent),
            $parentClass,
            $metadata->getName(),
        ));
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
