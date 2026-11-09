<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ComputedPivot;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\BelongsToMany;

/**
 * Adds `staff`, the same mixed pivot the Doctrine kernel serves, read off the crew's
 * members. The in-memory store keeps no pivot storage, so only its computed `role` renders.
 */
final class InMemoryCpCrewResource extends CpCrewResource
{
    public function fields(): array
    {
        return [
            ...parent::fields(),
            BelongsToMany::make('staff', 'cp-members')
                ->extractUsing(static fn(mixed $crew, JsonApiRequestInterface $request): array => $crew instanceof CpCrew
                    ? (\is_array($crew->members) ? \array_values($crew->members) : \iterator_to_array($crew->members, false))
                    : [])
                ->fields(...self::staffFields()),
        ];
    }
}
