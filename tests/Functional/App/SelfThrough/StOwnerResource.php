<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\SelfThrough;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\BelongsToManyBuilder;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Filter\WhereIdIn;
use haddowg\JsonApiBundle\Tests\Functional\App\IdMatrix\PrefixUuidEncoder;

/**
 * Owners seated beside owners of their own type: `peers` are the owners each one seats,
 * `followers` the owners seating it. On Doctrine both read one seat entity, holding two
 * associations to the owner class: `peers` is stored as the owner's collection mapped by
 * its own side, and `followers` names its sides in `through()`. The in-memory store keeps
 * the member lists.
 */
abstract class StOwnerResource extends AbstractResource
{
    /**
     * The seat entity on Doctrine, or `null` in memory.
     */
    protected const ?string SEAT = null;

    protected const bool ENCODED = false;

    public function fields(): array
    {
        $id = Id::make()->allowClientId();

        return [
            static::ENCODED ? $id->encodeUsing(new PrefixUuidEncoder()) : $id,
            Str::make('name'),
            $this->members('peers', 'seats'),
            $this->members('followers', 'peerSeats')->withData(),
            ...(static::SEAT === null ? [] : [
                // The same seats with no pivot field: the through class alone makes them its rows.
                BelongsToMany::make('mates', static::$type)->through(static::SEAT, parent: 'owner', related: 'peer')->countable(),
            ]),
        ];
    }

    public function filters(): array
    {
        return [
            Where::make('peerName', 'name')->through('peers'),
            Where::make('followerName', 'name')->through('followers'),
            ...(static::SEAT === null ? [] : [Where::make('mateName', 'name')->through('mates')]),
        ];
    }

    private function members(string $name, string $collection): BelongsToManyBuilder
    {
        $relation = BelongsToMany::make($name, static::$type)->countable();
        if (static::SEAT === null) {
            return $relation;
        }

        // The row's own side of the seat, the parent, and the member's.
        [$parentSide, $memberSide] = $collection === 'seats' ? ['owner', 'peer'] : ['peer', 'owner'];

        // `peers` is stored as the seats mapped by its own side, and `followers` names its
        // sides, the two ways a relation through a self-linking entity says which is which.
        $relation = $collection === 'seats'
            ? $relation->storedAs($collection)->through(static::SEAT)
            : $relation->through(static::SEAT, parent: $parentSide, related: $memberSide);

        return $relation
            ->fields(Integer::make('rank'))
            ->withFilters(
                Where::make('rank', 'pivot.rank'),
                WhereIdIn::make('seatedBy')->through('pivot.' . $parentSide),
                WhereIdIn::make('seating')->through('pivot.' . $memberSide),
            );
    }
}
