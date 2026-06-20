<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use Doctrine\ORM\Mapping as ORM;

/**
 * The Doctrine-mapped `books` of the flattened-attribute (`on()`) fixture (bundle
 * ADR 0085), mirroring the in-memory
 * {@see \haddowg\JsonApiBundle\Tests\Functional\App\FlattenBook}. Three `ManyToOne`
 * associations (the owning side, so the FK lives on the book row):
 *
 *  - `author` — the HIDDEN backing of the flattened `authorName` attribute AND the
 *    first hop of the multi-hop `authorCountry` (`on('author.country')`). It is a
 *    SEPARATE, no-other-relation-reads association, so a plain `/books` fetch leaves
 *    it LAZY (an uninitialised proxy) and the per-row flattened read would trigger one
 *    `SELECT` per book — the N+1 the eager batch collapses to a single `WHERE id IN`
 *    load (the budget witness); the author's own lazy `country` proxy then collapses
 *    to one `WHERE id IN` country load at the second hop (the multi-hop budget witness);
 *  - `publisher` — backs no rendered attribute (a sibling registered type, populated
 *    but not flattened or pinned);
 *  - `editor` — a VISIBLE `ManyToOne` to `authors`, backing the flattened
 *    `editorName`. It renders as a relationship and can be associated in a write
 *    body, so a same-body write witnesses the relationship-before-flatten order.
 *
 * Not `final` so Doctrine may proxy it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'flatten_book')]
class FlattenBookEntity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\GeneratedValue(strategy: 'AUTO')]
        #[ORM\Column]
        public ?int $id = null,
        #[ORM\Column]
        public string $title = '',
        #[ORM\ManyToOne(targetEntity: FlattenAuthorEntity::class)]
        #[ORM\JoinColumn(name: 'author_id', referencedColumnName: 'id', nullable: true)]
        public ?FlattenAuthorEntity $author = null,
        #[ORM\ManyToOne(targetEntity: FlattenPublisherEntity::class)]
        #[ORM\JoinColumn(name: 'publisher_id', referencedColumnName: 'id', nullable: true)]
        public ?FlattenPublisherEntity $publisher = null,
        #[ORM\ManyToOne(targetEntity: FlattenAuthorEntity::class)]
        #[ORM\JoinColumn(name: 'editor_id', referencedColumnName: 'id', nullable: true)]
        public ?FlattenAuthorEntity $editor = null,
    ) {}
}
