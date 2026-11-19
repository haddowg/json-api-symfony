<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\PivotRelationInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\Through\PivotRowRelations;

/**
 * Names the relations a pivot row holds from its association entity's mapping, the entity
 * {@see PivotAssociationResolver} finds for the relation, as the pivot-row filters walk it.
 * An association of that entity is the relation under its property name, a to-one or a
 * to-many as mapped, reaching the one registered type whose entity it targets.
 *
 * @internal
 */
final class DoctrinePivotRowRelations implements PivotRowRelations
{
    /**
     * @var array<string, RelationInterface>
     */
    private array $relations = [];

    /**
     * @param array<string, class-string> $entityClassByType
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly array $entityClassByType,
        private readonly ?PivotAssociationResolver $pivotAssociations,
    ) {}

    /**
     * @throws \LogicException when no registered type, or more than one, serves the entity the association targets
     */
    public function pivotRowRelation(PivotRelationInterface $relation, string $parentType, string $member): ?RelationInterface
    {
        $rowClass = $this->rowClass($relation, $parentType);
        if ($rowClass === null) {
            return null;
        }

        $metadata = $this->entityManager->getClassMetadata($rowClass);
        if (!$metadata->hasAssociation($member)) {
            return null;
        }

        $key = \spl_object_id($relation) . '|' . $parentType . '|' . $member;
        if (isset($this->relations[$key])) {
            return $this->relations[$key];
        }

        $target = $this->entityManager->getClassMetadata($metadata->getAssociationTargetClass($member))->getName();
        $types = [];
        foreach ($this->entityClassByType as $type => $class) {
            if ($this->entityManager->getClassMetadata($class)->getName() === $target) {
                $types[] = $type;
            }
        }
        if (\count($types) !== 1) {
            throw new \LogicException(\sprintf(
                $types === []
                    ? 'The pivot rows of relation "%s" hold "%s", an association to %s, which no registered type serves, so a path through it reaches no type whose rules apply.'
                    : 'The pivot rows of relation "%s" hold "%s", an association to %s, which several types serve (%s), so a path through it can\'t tell whose rules apply.',
                $relation->name(),
                $member,
                $target,
                \implode(', ', $types),
            ));
        }

        return $this->relations[$key] = $metadata->isCollectionValuedAssociation($member)
            ? HasMany::make($member, $types[0])->build()
            : BelongsTo::make($member, $types[0])->build();
    }

    /**
     * The entity backing `$relation`'s pivot rows, or `null` when this provider reads none.
     *
     * @return class-string|null
     */
    private function rowClass(PivotRelationInterface $relation, string $parentType): ?string
    {
        if (!$relation instanceof BelongsToMany) {
            return null;
        }

        $parentClass = $this->entityClassByType[$parentType] ?? null;
        $farClass = $this->entityClassByType[$relation->relatedTypes()[0] ?? ''] ?? null;
        if ($this->pivotAssociations === null || $parentClass === null || $farClass === null) {
            return null;
        }

        return $this->pivotAssociations->discover($relation, $parentClass, $farClass)->entityClass;
    }
}
