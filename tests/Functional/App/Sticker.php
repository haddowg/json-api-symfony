<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

/**
 * The far (related) `stickers` type of the strict-sparse-fieldset-member fixture: a
 * plain id/label object a leaflet's to-one `sticker` relationship links to.
 * Registering it makes the type known to the serializer resolver, so the
 * relationship can emit linkage and `?include=sticker` can expand it — the path
 * under which a `fields[stickers]` member is validated for an INCLUDED related type.
 * Mirrors {@see Doctrine\StickerEntity}.
 */
final class Sticker
{
    public function __construct(
        public ?int $id = null,
        public string $label = '',
    ) {}
}
