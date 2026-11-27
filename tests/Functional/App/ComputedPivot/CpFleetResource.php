<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ComputedPivot;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\MorphToMany;
use haddowg\JsonApi\Resource\Field\PivotEdge;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * A fleet's `vessels` holds ships and crews by stored pairs, and its `units` holds its
 * ships and its flagship's crew, read off the fleet. Its `hands` is a pivot relation
 * declared `notSparseField()`, with a computed `rank`.
 */
#[AsJsonApiResource(readOnly: true)]
class CpFleetResource extends AbstractResource
{
    public static string $type = 'cp-fleets';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            HasMany::make('ships', 'cp-ships'),
            BelongsTo::make('flagship', 'cp-ships'),
            BelongsToMany::make('hands', 'cp-members')->notSparseField()->fields(
                Str::make('rank')->computedUsing(static fn(mixed $edge): string => $edge instanceof PivotEdge && $edge->parent instanceof CpFleet && $edge->related instanceof CpMember
                    ? $edge->parent->name . ':' . $edge->related->name
                    : ''),
            ),
            MorphToMany::make('vessels', ['cp-ships', 'cp-crews'])
                ->identifiedBy(static fn(mixed $fleet): array => $fleet instanceof CpFleet ? $fleet->vessels : []),
            MorphToMany::make('units', ['cp-ships', 'cp-crews'])
                ->readOnly()
                ->extractUsing(static function (mixed $fleet): array {
                    if (!$fleet instanceof CpFleet) {
                        return [];
                    }
                    $units = \is_array($fleet->ships) ? $fleet->ships : \iterator_to_array($fleet->ships, false);
                    if ($fleet->flagship?->crew !== null) {
                        $units[] = $fleet->flagship->crew;
                    }

                    return \array_values($units);
                }),
        ];
    }
}
