<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\CatalogGate;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\MorphToMany;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * Any user may read a hub. Only an admin may list spokes, so `spokes` and the
 * polymorphic `parts` are refused to everyone else, while `rims`, the to-one
 * `leadSpoke` and `sharedSpokes`, whose own read permission replaces the spokes'
 * collection gate, are not.
 */
#[AsJsonApiResource(readOnly: true, securityRead: "is_granted('ROLE_USER')")]
final class HubResource extends AbstractResource
{
    public static string $type = 'hubs';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('owner'),
            BelongsToMany::make('spokes', 'spokes'),
            BelongsToMany::make('rims', 'rims'),
            MorphToMany::make('parts', ['rims', 'spokes'])->readOnly()->extractUsing(
                static fn(mixed $hub): array => $hub instanceof Hub ? [...$hub->rims, ...$hub->spokes] : [],
            ),
            BelongsToMany::make('sharedSpokes', 'spokes')->storedAs('spokes')->security(read: "is_granted('ROLE_USER')"),
            BelongsTo::make('leadSpoke', 'spokes')->nullable(),
        ];
    }
}
