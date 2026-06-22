<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\EagerValidation;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * The subject `products` of the fail-loud eager-load validation fixture (bundle ADR
 * 0085): its relation inventory covers every shape the boot-time
 * {@see \haddowg\JsonApi\Serializer\EagerLoadValidator} discriminates, plus a single
 * flattened attribute whose `on()` chain a subclass overrides via {@see flattenPath()}
 * to pin one shape and witness the throw / acceptance:
 *
 *  - `region` — a HIDDEN to-one to `brands` (which itself declares a to-one `region`),
 *    so a valid multi-hop `region.region` walks two to-one hops — the SAFE chain;
 *  - `tags` — a to-many to `brands`: a to-many segment at ANY depth is NOT flattenable
 *    (the throw), whether it is the leaf (`tags`) or an ancestor (`tags.region`);
 *  - `nope` — names no declared relation (the unknown-segment throw).
 *
 * Every `on()` chain is the dedup set the resource's {@see eagerLoadRelationshipPaths()}
 * exposes; a subclass overrides only {@see flattenPath()}.
 */
abstract class BaseEagerProductResource extends AbstractResource
{
    public static string $type = 'products';

    /**
     * The `on()` chain the single flattened attribute declares — the one shape this
     * subject pins for the warm-up validator to accept or reject.
     */
    abstract protected function flattenPath(): string;

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            // The single flattened attribute whose `on()` chain the subject pins.
            Str::make('flat')->storedAs('name')->on($this->flattenPath()),
            // A hidden to-one to `brands` (which declares its own to-one `region`), so
            // `region.region` is a valid two-hop to-one chain — the SAFE shape.
            BelongsTo::make('region', 'brands')->hidden(),
            // A to-many: a to-many segment at any depth is not flattenable. `brands`
            // declares relations, so `tags.region` is walkable to a second level — the
            // ANCESTOR-to-many case.
            HasMany::make('tags', 'brands')->hidden(),
        ];
    }
}
