<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

/**
 * The primary `leaflets` type of the strict-sparse-fieldset-member fixture: a plain
 * id/title object carrying a couple of non-rendered members and a to-one link to
 * {@see Sticker}, so its resource can declare the full member namespace the strict
 * `fields[type]` check resolves against — a known sparse field (`title`), an
 * always-hidden field (`secret`), a non-sparse field (`internalRef`) and a
 * relationship (`sticker`). Mirrors {@see Doctrine\LeafletEntity} so the dual-provider
 * assertions run identically on both providers.
 */
final class Leaflet
{
    public function __construct(
        public ?int $id = null,
        public string $title = '',
        public string $secret = '',
        public string $internalRef = '',
        public ?Sticker $sticker = null,
    ) {}
}
