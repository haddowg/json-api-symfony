<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use Doctrine\ORM\Mapping as ORM;

/**
 * The Doctrine-mapped far (related) `stickers` type of the strict-sparse-fieldset
 * fixture, mirroring the in-memory
 * {@see \haddowg\JsonApiBundle\Tests\Functional\App\Sticker}. A store-provided `AUTO`
 * integer id and a single `label` column. Not `final` so Doctrine may proxy it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'sticker')]
class StickerEntity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\GeneratedValue(strategy: 'AUTO')]
        #[ORM\Column]
        public ?int $id = null,
        #[ORM\Column]
        public string $label = '',
    ) {}
}
