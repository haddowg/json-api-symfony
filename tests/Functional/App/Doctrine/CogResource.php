<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * The Doctrine encoded-id witness resource (bundle ADR 0038): the `cogs` type keys
 * its entity by an integer storage key that never reaches the wire — {@see Id::encodeUsing()}
 * attaches a {@see HexIdEncoder} so the JSON:API `id` and URL are an opaque `cog-…`
 * token, and {@see Id::matchAs()} pins the route `{id}` to that shape (a malformed id
 * 404s at routing). A self-referential `parent` relation exercises the persister's
 * linkage decode.
 *
 * Its {@see CogEntity} is keyed by an application-assigned integer, so the resource
 * accepts a client-generated id: a `POST /cogs` carrying an encoded `cog-…` wire id
 * decodes it to the integer storage key on create, proving the create round-trip
 * (the entity holds the storage key, the response re-encodes it to the wire id).
 */
#[AsJsonApiResource(entity: CogEntity::class)]
final class CogResource extends AbstractResource
{
    public static string $type = 'cogs';

    public function fields(): array
    {
        return [
            // The {@see CogEntity} keys on an application-assigned integer storage
            // key (no DB sequence), so a create must carry the encoded `cog-…` wire
            // id — `requireClientId()` mandates it (a create without one 403s), the
            // policy that supersedes the removed `acceptsClientGeneratedId()` (bundle
            // ADR 0039). The encoder decodes the wire id to the integer storage key.
            Id::make()
                ->requireClientId()
                ->encodeUsing(new HexIdEncoder())
                ->matchAs('cog-[0-9a-f]+'),
            Str::make('name')->required(),
            BelongsTo::make('parent')->type('cogs')->nullable(),
        ];
    }
}
