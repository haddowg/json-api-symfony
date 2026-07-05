<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A physical or digital release of an {@see Album} — the composite-attribute
 * showcase (core ADRs 0118–0121). Each composite attribute persists as a
 * **single `json` column**, the storage decision for composite types: `format`
 * backs a discriminated {@see \haddowg\JsonApi\Resource\Field\OneOf} (its
 * `medium` key selects the vinyl/cd/digital variant), `packaging` a typed
 * {@see \haddowg\JsonApi\Resource\Field\Obj}, and `availability`/`dimensions`
 * free-form maps whose shapes are asserted by
 * {@see \haddowg\JsonApi\Resource\Constraint\Shape} composite schemas.
 *
 * The table is `album_release` because `release` is a reserved word on MySQL.
 * The id is a database-assigned auto-increment integer (the example's norm).
 * Not `final` so Doctrine may proxy it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'album_release')]
class Release
{
    /**
     * @param array<string, mixed>|null $format
     * @param array<string, mixed>|null $packaging
     * @param array<string, mixed>|null $availability
     * @param array<string, mixed>|null $dimensions
     */
    public function __construct(
        #[ORM\Id]
        #[ORM\GeneratedValue(strategy: 'AUTO')]
        #[ORM\Column]
        public ?int $id = null,
        #[ORM\Column(length: 40)]
        public string $catalogNumber = '',
        #[ORM\Column(type: 'json', nullable: true)]
        public ?array $format = null,
        #[ORM\Column(type: 'json', nullable: true)]
        public ?array $packaging = null,
        #[ORM\Column(type: 'json', nullable: true)]
        public ?array $availability = null,
        #[ORM\Column(type: 'json', nullable: true)]
        public ?array $dimensions = null,
        #[ORM\ManyToOne(targetEntity: Album::class)]
        #[ORM\JoinColumn(name: 'album_id', referencedColumnName: 'id', nullable: true)]
        public ?Album $album = null,
    ) {}
}
