<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use Doctrine\ORM\Mapping as ORM;

/**
 * The Doctrine-mapped sheet, the same shape as the in-memory
 * {@see \haddowg\JsonApiBundle\Tests\Functional\App\OpenObject\Sheet}: each object
 * attribute and the row list is one `json` column. Not `final` so Doctrine may proxy it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'sheet')]
class SheetEntity
{
    /**
     * @param list<mixed>|null          $rows
     * @param array<string, mixed>|null $labels
     * @param array<string, mixed>|null $settings
     * @param array<string, mixed>|null $metrics
     */
    public function __construct(
        #[ORM\Id]
        #[ORM\GeneratedValue(strategy: 'AUTO')]
        #[ORM\Column]
        public ?int $id = null,
        #[ORM\Column]
        public string $name = '',
        #[ORM\Column(type: 'json', nullable: true)]
        public ?array $rows = null,
        #[ORM\Column(type: 'json', nullable: true)]
        public ?array $labels = null,
        #[ORM\Column(type: 'json', nullable: true)]
        public ?array $settings = null,
        #[ORM\Column(type: 'json', nullable: true)]
        public ?array $metrics = null,
    ) {}
}
