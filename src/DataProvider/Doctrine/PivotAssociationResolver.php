<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\RelationInterface;

/**
 * Resolves the Doctrine association entity backing a `belongsToMany` pivot
 * relation (a {@see BelongsToMany} declaring {@see BelongsToMany::pivotFields()}).
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
 * Resolution is cached per `(relation-name, parent-class, far-class)` so a
 * repeated related fetch does not re-scan metadata.
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
     * declaring at least one pivot field. A `belongsToMany` without pivot fields
     * (or any other to-many) is NOT pivot-backed and keeps the plain related-collection
     * path.
     */
    public function isPivotRelation(RelationInterface $relation): bool
    {
        return $relation instanceof BelongsToMany && $relation->pivotFields() !== [];
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
        $cacheKey = $relation->name() . "\0" . $parentClass . "\0" . $farClass;

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

        $parentProperty = $this->singleValuedTargeting($metadata, $parentClass);
        $farProperty = $this->singleValuedTargeting($metadata, $farClass);

        if ($parentProperty === null || $farProperty === null) {
            throw new \LogicException(\sprintf(
                'The pivot relation "%s" declares through("%s"), but that entity does not carry a ManyToOne to both the parent (%s) and the related type (%s).',
                $relation->name(),
                $through,
                $parentClass,
                $farClass,
            ));
        }

        /** @var class-string $entityClass */
        $entityClass = $metadata->getName();

        return new PivotAssociation($entityClass, $parentProperty, $farProperty);
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
        foreach ($parentMetadata->getAssociationMappings() as $field => $mapping) {
            if (!$parentMetadata->isCollectionValuedAssociation((string) $field)) {
                continue;
            }

            /** @var class-string $candidateClass */
            $candidateClass = $mapping->targetEntity;
            $candidateMetadata = $this->entityManager->getClassMetadata($candidateClass);

            $farProperty = $this->singleValuedTargeting($candidateMetadata, $farClass);
            if ($farProperty === null) {
                continue;
            }

            $parentProperty = $this->singleValuedTargeting($candidateMetadata, $parentClass);
            if ($parentProperty === null) {
                continue;
            }

            /** @var class-string $entityClass */
            $entityClass = $candidateMetadata->getName();
            $matches[$entityClass] = new PivotAssociation($entityClass, $parentProperty, $farProperty);
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
     * The name of the single-valued (`ManyToOne`/`OneToOne`) association on
     * `$metadata` whose target is `$targetClass`, or `null` when none matches.
     *
     * @param ClassMetadata<object> $metadata
     * @param class-string          $targetClass
     */
    private function singleValuedTargeting(ClassMetadata $metadata, string $targetClass): ?string
    {
        $targetName = $this->entityManager->getClassMetadata($targetClass)->getName();

        foreach ($metadata->getAssociationMappings() as $field => $mapping) {
            if (!$metadata->isSingleValuedAssociation((string) $field)) {
                continue;
            }

            if ($this->entityManager->getClassMetadata($mapping->targetEntity)->getName() === $targetName) {
                return (string) $field;
            }
        }

        return null;
    }
}
