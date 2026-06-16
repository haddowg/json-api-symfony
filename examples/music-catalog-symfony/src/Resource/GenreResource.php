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
 *
 * It is also the **declarative HTTP cache-header** witness (bundle ADR 0054,
 * API-Platform gap G7). A genre is reference/lookup data that changes rarely, so its
 * reads are long-cacheable: `cacheHeaders` declares a one-hour client lifetime
 * (`max_age`), a one-day CDN lifetime (`s_maxage`), `public`, and `Vary: Accept` so a
 * cache keys per negotiated media type. The collection lists genres, which churns a
 * little more often than a single genre, so a nested `operations.collection` override
 * shortens just its `max_age` to five minutes — layered over the resource-level
 * directives, which the `read` shape (a single genre) keeps unchanged. The bundle's
 * `ResponseHeadersListener` emits these on a successful `GET` only; a write
 * (`POST /genres`) and an error never carry a `Cache-Control` (caching either is
 * wrong).
 */
#[AsJsonApiResource(
    entity: Genre::class,
    cacheHeaders: [
        'max_age' => 3600,
        's_maxage' => 86400,
        'public' => true,
        'vary' => ['Accept'],
        'operations' => [
            'collection' => ['max_age' => 300],
        ],
    ],
)]
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
