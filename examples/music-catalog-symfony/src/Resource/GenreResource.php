<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Resource;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Examples\MusicCatalog\Entity\Genre;

/**
 * The `genres` resource type — the **client-supplied natural-key** id-strategy
 * demonstrator (bundle ADR 0039). A genre's identity is its slug, a stable key the
 * client owns, so `Id::make()->requireClientId()` makes a create's `data.id`
 * MANDATORY: `POST /genres` with no id is a `403` (`ClientGeneratedIdRequired`),
 * while a create that supplies `"trip-hop"` uses it verbatim as the primary key.
 *
 * `pattern()` additionally constrains the natural key to a lowercase slug shape,
 * both pinning the route `{id}` and validating the wire id through the bundle's
 * Symfony Validator bridge (a malformed key 422s at `/data/id`).
 */
#[AsJsonApiResource(entity: Genre::class)]
final class GenreResource extends AbstractResource
{
    public static string $type = 'genres';

    public function fields(): array
    {
        return [
            Id::make()->requireClientId()->pattern('^[a-z0-9]+(?:-[a-z0-9]+)*$'),
            Str::make('name')->required(),
        ];
    }
}
