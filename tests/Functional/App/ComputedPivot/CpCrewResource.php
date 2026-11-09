<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ComputedPivot;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\PivotEdge;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * A crew's `members` declares only computed pivot fields, so it is served over its plain
 * join table with no association entity: `role` reads both ends of the link, and `badge`
 * is hidden from one member by predicate.
 */
class CpCrewResource extends AbstractResource
{
    public static string $type = 'cp-crews';

    public function fields(): array
    {
        return [
            Id::make()->allowClientId(),
            Str::make('name'),
            BelongsToMany::make('members', 'cp-members')->fields(
                Str::make('role')->computedUsing(self::role(...)),
                Str::make('badge')
                    ->extractUsing(static fn(mixed $edge): string => $edge instanceof PivotEdge && $edge->related instanceof CpMember ? \strtoupper($edge->related->name) : '')
                    ->hidden(static fn(mixed $member, JsonApiRequestInterface $request): bool => $member instanceof CpMember && $member->name === 'Linus'),
            ),
        ];
    }

    protected static function role(mixed $edge): string
    {
        return $edge instanceof PivotEdge && $edge->parent instanceof CpCrew && $edge->related instanceof CpMember
            ? $edge->parent->name . ':' . $edge->related->name
            : '';
    }
}
