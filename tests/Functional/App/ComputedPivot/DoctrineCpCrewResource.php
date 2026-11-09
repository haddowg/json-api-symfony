<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ComputedPivot;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * Adds `staff`, a pivot mixing the stored `shift` column of {@see CpShift}, the stored
 * `note` column read through an extract hook that upper-cases it, and a computed `role`,
 * which still needs its association entity.
 */
#[AsJsonApiResource(entity: CpCrew::class)]
final class DoctrineCpCrewResource extends CpCrewResource
{
    public function fields(): array
    {
        return [
            ...parent::fields(),
            BelongsToMany::make('staff', 'cp-members')
                ->through(CpShift::class)
                ->extractUsing(static fn(mixed $crew, JsonApiRequestInterface $request): array => $crew instanceof CpCrew && isset($crew->shifts)
                    ? \array_values(\array_map(static fn(CpShift $shift): CpMember => $shift->member, $crew->shifts->toArray()))
                    : [])
                ->fields(...self::staffFields()),
        ];
    }
}
