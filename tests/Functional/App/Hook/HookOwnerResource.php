<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Hook;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * The related `hookOwners` resource: registering it makes the type known to the
 * serializer resolver so a {@see HookWidget}'s `owner` relationship can emit
 * `{type: 'hookOwners', id: …}` linkage.
 */
final class HookOwnerResource extends AbstractResource
{
    public static string $type = 'hookOwners';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
        ];
    }
}
