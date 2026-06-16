<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A merch product — the encoded-id witness (bundle ADR 0038). Its primary key is a
 * database-generated **integer**, never exposed on the wire: the {@see \haddowg\JsonApiBundle\Examples\MusicCatalog\Resource\ProductResource}
 * attaches a {@see \haddowg\JsonApiBundle\Examples\MusicCatalog\Resource\ProductIdCodec}
 * so the JSON:API `id` (and the URL) is an opaque `prod-…` token that encodes this
 * integer storage key.
 *
 * A self-referential `parent` ({@see ORM\ManyToOne} to another `Product`) gives the
 * relationship-write path a linkage whose target id is itself an encoded `products`
 * wire id — so the persister's linkage decode is exercised end-to-end.
 *
 * The id is database-generated (so a create does not carry one), proving storage !=
 * wire without a client-supplied id. Not `final` so Doctrine may proxy it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'product')]
class Product
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    public function __construct(
        #[ORM\Column]
        public string $name = '',
        #[ORM\ManyToOne(targetEntity: self::class)]
        #[ORM\JoinColumn(name: 'parent_id', referencedColumnName: 'id', nullable: true)]
        public ?Product $parent = null,
    ) {}
}
