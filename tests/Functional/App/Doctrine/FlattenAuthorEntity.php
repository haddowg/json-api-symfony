<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use Doctrine\ORM\Mapping as ORM;

/**
 * The Doctrine-mapped `authors` of the flattened-attribute (`on()`) fixture (bundle
 * ADR 0085), mirroring the in-memory
 * {@see \haddowg\JsonApiBundle\Tests\Functional\App\FlattenAuthor}. A store-provided
 * `AUTO` integer id; `name` is the member a book's `authorName` flattens. A
 * flattened-attribute PATCH mutates the managed author's `name`, which Doctrine's
 * unit of work auto-persists on flush (no related-persister change). Not `final` so
 * Doctrine may proxy it.
 *
 * It also carries a HIDDEN `ManyToOne` `country` (the owning side, FK on the author
 * row): the SECOND hop the book's multi-hop `on('author.country')` walks to. LAZY by
 * default, so the multi-hop eager walk's collapse to ONE batched `WHERE id IN` country
 * load (the multi-hop budget witness) is observable.
 */
#[ORM\Entity]
#[ORM\Table(name: 'flatten_author')]
class FlattenAuthorEntity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\GeneratedValue(strategy: 'AUTO')]
        #[ORM\Column]
        public ?int $id = null,
        #[ORM\Column]
        public string $name = '',
        #[ORM\ManyToOne(targetEntity: FlattenCountryEntity::class)]
        #[ORM\JoinColumn(name: 'country_id', referencedColumnName: 'id', nullable: true)]
        public ?FlattenCountryEntity $country = null,
    ) {}
}
