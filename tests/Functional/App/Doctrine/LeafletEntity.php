<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use Doctrine\ORM\Mapping as ORM;

/**
 * The Doctrine-mapped primary `leaflets` type of the strict-sparse-fieldset fixture —
 * the same shape as the in-memory
 * {@see \haddowg\JsonApiBundle\Tests\Functional\App\Leaflet}, persisted to the test
 * SQLite database. A store-provided `AUTO` integer id; the `secret` and `internalRef`
 * columns back the always-hidden and non-sparse fields; the to-one `sticker` is a
 * {@see ORM\ManyToOne} so `?include=sticker` expands the related resource whose
 * `fields[stickers]` members are validated. Not `final` so Doctrine may proxy it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'leaflet')]
class LeafletEntity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\GeneratedValue(strategy: 'AUTO')]
        #[ORM\Column]
        public ?int $id = null,
        #[ORM\Column]
        public string $title = '',
        #[ORM\Column]
        public string $secret = '',
        #[ORM\Column]
        public string $internalRef = '',
        #[ORM\ManyToOne(targetEntity: StickerEntity::class)]
        public ?StickerEntity $sticker = null,
    ) {}
}
