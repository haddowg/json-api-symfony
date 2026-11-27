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
 * associations to the owner class, and each relation is stored as the owner's collection
 * mapped by its own side. The in-memory store keeps the member lists.
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
        ];
    }

    public function filters(): array
    {
        return [
            Where::make('peerName', 'name')->through('peers'),
            Where::make('followerName', 'name')->through('followers'),
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

        return $relation->storedAs($collection)
            ->through(static::SEAT)
            ->fields(Integer::make('rank'))
            ->withFilters(
                Where::make('rank', 'pivot.rank'),
                WhereIdIn::make('seatedBy')->through('pivot.' . $parentSide),
                WhereIdIn::make('seating')->through('pivot.' . $memberSide),
            );
    }
}
