<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use Doctrine\ORM\Mapping as ORM;

/**
 * The Doctrine-mapped `publishers` of the flattened-attribute (`on()`) fixture
 * (bundle ADR 0085), mirroring the in-memory
 * {@see \haddowg\JsonApiBundle\Tests\Functional\App\FlattenPublisher}. A
 * store-provided `AUTO` integer id. A sibling registered type a book carries a
 * `publisher` FK to — it backs no flattened attribute and is no longer eager-pinned;
 * it stays only to keep the seeded book graph realistic. Not `final` so Doctrine may
 * proxy it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'flatten_publisher')]
class FlattenPublisherEntity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\GeneratedValue(strategy: 'AUTO')]
        #[ORM\Column]
        public ?int $id = null,
        #[ORM\Column]
        public string $name = '',
    ) {}
}
