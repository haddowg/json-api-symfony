<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Resource;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * The shared `leaflets` declaration both strict-sparse-fieldset kernels serve: one
 * field inventory, so the in-memory and Doctrine providers are exercised by
 * **identical** assertions and a failure localizes to the provider, not the fixture
 * (mirroring the request-aware-predicate suite layout).
 *
 * The inventory is chosen to cover the whole `fields[leaflets]` member namespace the
 * strict check resolves against ({@see \haddowg\JsonApi\Serializer\DeclaresFieldNamesInterface}):
 *  - `title` — an ordinary, rendered, sparse-eligible attribute (the "known member"
 *    a positive `fields[leaflets]=title` request names);
 *  - `secret` — **unconditionally hidden** (`hidden()`): never rendered, yet still a
 *    DECLARED field, so `fields[leaflets]=secret` is tolerated (no information leak —
 *    a hidden name and a bogus name would otherwise be distinguishable);
 *  - `internalRef` — **non-sparse** (`notSparseField()`): always present regardless of
 *    the sparse set, yet still a declared field, so naming it is tolerated;
 *  - `sticker` — a to-one **relationship**, also a declared field name, so it too is a
 *    valid `fields[leaflets]` member and the includable path to a related type whose
 *    own `fields[stickers]` members are validated.
 *
 * `id` is implicitly a declared field too, so `fields[leaflets]=id` is tolerated. A
 * member is "unknown" — a `400` {@see \haddowg\JsonApi\Exception\FieldsetMemberUnrecognized}
 * under the strict gate — only when it names none of these (a real typo such as
 * `bogus`).
 */
abstract class BaseLeafletResource extends AbstractResource
{
    public static string $type = 'leaflets';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title'),
            // Unconditionally hidden: never rendered, but a declared field name — so
            // naming it in fields[leaflets] is tolerated (no information leak).
            Str::make('secret')->hidden(),
            // Non-sparse: always present whatever the sparse set, but still a declared
            // field name — so naming it is tolerated.
            Str::make('internalRef')->notSparseField(),
            BelongsTo::make('sticker', 'stickers'),
        ];
    }
}
