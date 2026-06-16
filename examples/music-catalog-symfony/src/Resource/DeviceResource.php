<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Resource;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Examples\MusicCatalog\Entity\Device;

/**
 * The `devices` resource type — the **app-generated ULID** id-strategy demonstrator
 * (bundle ADR 0039). A device is minted server-side, so `Id::make()->ulid()->generated()`
 * has core mint a Crockford-base32 {@see \haddowg\JsonApi\Resource\Field\Ulid ULID}
 * when a create omits the id (`POST /devices` with just a `label` returns `201` with
 * a fresh ULID). `ulid()` pins the route `{id}` shape and validates the wire id, so a
 * client id of the wrong shape is a `422` — but the common path is the store/app
 * minting it, never a client.
 *
 * It is also the **self-link opt-out** witness (core ADR 0054, bundle ADR 0047):
 * core emits a convention `data.links.self` (`{baseUri}/{uriType}/{id}`) on every
 * resource object by default; overriding {@see emitsSelfLink()} to return `false`
 * suppresses it for THIS type only. A `GET /devices/{id}` therefore carries no
 * `data.links.self`, while the top-level document `links.self` (the request URI) is
 * unaffected — the opt-out is resource-scoped.
 */
#[AsJsonApiResource(entity: Device::class)]
final class DeviceResource extends AbstractResource
{
    public static string $type = 'devices';

    /**
     * Opts the `devices` type out of the convention resource `self` link, so its
     * resource objects carry no `data.links.self` (the top-level document `self`
     * still renders — the opt-out is per-resource, not per-document).
     */
    public function emitsSelfLink(): bool
    {
        return false;
    }

    public function fields(): array
    {
        return [
            Id::make()->ulid()->generated(),
            Str::make('label')->required(),
        ];
    }
}
