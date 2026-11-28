<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\EncodedPivot;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * An in-memory team whose id and whose players' ids render through prefixing encoders, with
 * a `shirt` kept on each player's pivot row.
 */
final class EncTeamResource extends AbstractResource
{
    public static string $type = 'enc-teams';

    public function fields(): array
    {
        return [
            Id::make()->encodeUsing(new PrefixEncoder('team-')),
            Str::make('name'),
            BelongsToMany::make('players', 'enc-players')->fields(Integer::make('shirt')->nullable()->build()),
        ];
    }
}
