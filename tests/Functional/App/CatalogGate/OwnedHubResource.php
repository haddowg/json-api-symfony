<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\CatalogGate;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * A hub only its owner may read, whose `spokes` also need the spokes' collection gate.
 */
#[AsJsonApiResource(readOnly: true, securityRead: 'object.owner == user.getUserIdentifier()')]
final class OwnedHubResource extends AbstractResource
{
    public static string $type = 'ownedHubs';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('owner'),
            BelongsToMany::make('spokes', 'spokes'),
        ];
    }
}
