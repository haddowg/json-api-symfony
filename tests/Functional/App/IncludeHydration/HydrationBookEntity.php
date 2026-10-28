<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IncludeHydration;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'hydration_book')]
class HydrationBookEntity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\GeneratedValue(strategy: 'AUTO')]
        #[ORM\Column]
        public ?int $id = null,
        #[ORM\Column]
        public string $title = '',
        #[ORM\ManyToOne(targetEntity: HydrationGenreEntity::class)]
        #[ORM\JoinColumn(name: 'genre_id', nullable: true)]
        public ?HydrationGenreEntity $genre = null,
    ) {}
}
