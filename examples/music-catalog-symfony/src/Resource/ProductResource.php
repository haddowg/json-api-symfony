<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Resource;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Examples\MusicCatalog\Entity\Product;

/**
 * The `products` resource type — the **encoded resource id** witness (bundle ADR
 * 0038). Its {@see Product} entity is keyed by a database-generated integer that
 * never reaches the wire: {@see Id::encodeUsing()} attaches a
 * {@see ProductIdCodec} so the JSON:API `id` and the URL are an opaque `prod-…`
 * token, and {@see Id::matchAs()} constrains the route `{id}` to that token shape
 * (a malformed id therefore `404`s at routing before any handler runs).
 *
 * The reference Doctrine layer decodes the wire id back to the integer storage key
 * before every lookup/`getReference`, while the storage-agnostic provider/persister
 * SPI stays wire-id throughout — so the encode/decode boundary lives entirely in
 * core (entity-own id) and the Doctrine impl (id-as-lookup-key). A self-referential
 * `parent` relation exercises the linkage decode: a relationship write whose
 * linkage `id` is an encoded `products` token resolves the right managed reference.
 *
 * Its id is **database-generated** — the **encoded store-provided** id-strategy
 * demonstrator (bundle ADR 0039). A `POST` carries no id; the plain `Id::make()`
 * (plus the codec) sets nothing, the database assigns the auto-increment integer on
 * flush, and the `201` body + `Location` render the *encoded* wire token for the
 * newly assigned key. This is now a first-class create — the store-provided default
 * makes a DB-assigned id a coherent create, so the operation allow-list keeps the
 * full CRUD set (Create included). The whole point: storage key != wire id, with no
 * client-supplied id.
 */
#[AsJsonApiResource(entity: Product::class)]
final class ProductResource extends AbstractResource
{
    public static string $type = 'products';

    public function fields(): array
    {
        return [
            // The id is the wire form of the integer storage key. matchAs() pins the
            // route {id} to the codec's token shape; the format constraint side is
            // not needed here because the id is server-assigned (no client id).
            Id::make()
                ->encodeUsing(new ProductIdCodec())
                ->matchAs('prod-[0-9a-f]+'),
            Str::make('name')->required(),

            // A self-referential to-one: its linkage id is itself an encoded
            // `products` wire id, so a relationship write decodes it (keyed by the
            // related type, `products`) before resolving the reference.
            BelongsTo::make('parent')->type('products')->nullable(),
        ];
    }
}
