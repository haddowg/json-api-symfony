<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ComputedPivot;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApiBundle\DataProvider\InMemoryStore;

/**
 * Adds `staff`, the same mixed pivot the Doctrine kernel serves: the crew's members that
 * have a `staff` pivot row in the store holding the crew.
 */
final class InMemoryCpCrewResource extends CpCrewResource
{
    public function fields(): array
    {
        return [
            ...parent::fields(),
            BelongsToMany::make('staff', 'cp-members')
                ->extractUsing(static function (mixed $crew, JsonApiRequestInterface $request): array {
                    if (!$crew instanceof CpCrew) {
                        return [];
                    }
                    // The members with a `staff` pivot row, as the Doctrine crew's shifts hold them.
                    $rows = InMemoryStore::holding($crew)?->pivotRows($crew, 'staff') ?? [];
                    $members = \is_array($crew->members) ? \array_values($crew->members) : \iterator_to_array($crew->members, false);

                    return \array_values(\array_filter($members, static fn(CpMember $member): bool => isset($rows[(string) $member->id])));
                })
                ->fields(...self::staffFields()),
        ];
    }
}
