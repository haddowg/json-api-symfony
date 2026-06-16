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
 */
#[AsJsonApiResource(entity: Device::class)]
final class DeviceResource extends AbstractResource
{
    public static string $type = 'devices';

    public function fields(): array
    {
        return [
            Id::make()->ulid()->generated(),
            Str::make('label')->required(),
        ];
    }
}
