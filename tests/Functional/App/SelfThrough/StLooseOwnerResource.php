<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\SelfThrough;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;

/**
 * Owners whose `peers` read the seats without saying which side of a seat is theirs.
 */
final class StLooseOwnerResource extends AbstractResource
{
    public static string $type = 'st-loose-owners';

    public function fields(): array
    {
        return [
            Id::make(),
            BelongsToMany::make('peers', 'st-loose-owners')
                ->through(StSeat::class)
                ->fields(Integer::make('rank')),
        ];
    }
}
