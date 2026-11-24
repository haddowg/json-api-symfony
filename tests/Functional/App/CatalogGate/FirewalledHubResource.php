<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\CatalogGate;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * A hub whose read a firewall secures, so its `spokes` need an authenticated caller who
 * may also list spokes, while its `rims` need only the firewall.
 */
#[AsJsonApiResource(readOnly: true, securityRead: true)]
final class FirewalledHubResource extends AbstractResource
{
    public static string $type = 'firewalledHubs';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('owner'),
            BelongsToMany::make('spokes', 'spokes'),
            BelongsToMany::make('rims', 'rims'),
        ];
    }
}
