<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use Doctrine\ORM\Mapping as ORM;

/**
 * The Doctrine-mapped `countries` of the flattened-attribute (`on()`) fixture
 * (bundle ADR 0085), mirroring the in-memory
 * {@see \haddowg\JsonApiBundle\Tests\Functional\App\FlattenCountry}. The SECOND hop
 * the book's multi-hop `on('author.country')` walks to: a {@see FlattenAuthorEntity}'s
 * HIDDEN `ManyToOne` `country` points at it, LAZY by default so the multi-hop eager
 * walk's collapse to one batched `WHERE id IN` per level (the budget witness) is
 * observable. A store-provided `AUTO` integer id. Not `final` so Doctrine may proxy it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'flatten_country')]
class FlattenCountryEntity
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
