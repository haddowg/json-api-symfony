<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ComputedPivot;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\PivotEdge;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * A ship's `members` renders its linkage data without an include, each member carrying the
 * computed `post` of its link.
 */
class CpShipResource extends AbstractResource
{
    use TypedByClass;

    public static string $type = 'cp-ships';

    public function fields(): array
    {
        return [
            Id::make()->allowClientId(),
            Str::make('name'),
            BelongsTo::make('crew', 'cp-crews')->notSparseField(),
            BelongsTo::make('captain', 'cp-members'),
            BelongsToMany::make('members', 'cp-members')->withData()->fields(
                Str::make('post')->computedUsing(static fn(mixed $edge): string => $edge instanceof PivotEdge && $edge->parent instanceof CpShip && $edge->related instanceof CpMember
                    ? $edge->parent->name . ':' . $edge->related->name
                    : ''),
            ),
        ];
    }
}
