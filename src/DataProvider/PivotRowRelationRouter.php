<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider;

use haddowg\JsonApi\Resource\Field\PivotRelationInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\Through\PivotRowRelations;

/**
 * The relations a pivot row holds, asked of the provider that serves the pivot
 * relation's related type, the one that reads its pivot rows. A provider that implements
 * no {@see PivotRowRelations} holds no pivot rows, so it names none.
 *
 * @internal
 */
final readonly class PivotRowRelationRouter implements PivotRowRelations
{
    public function __construct(private DataProviderRegistry $providers) {}

    public function pivotRowRelation(PivotRelationInterface $relation, string $parentType, string $member): ?RelationInterface
    {
        foreach ($relation->relatedTypes() as $type) {
            if (!$this->providers->supportsType($type)) {
                continue;
            }

            $provider = $this->providers->forType($type);
            if ($provider instanceof PivotRowRelations) {
                return $provider->pivotRowRelation($relation, $parentType, $member);
            }
        }

        return null;
    }
}
