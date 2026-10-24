<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use Doctrine\ORM\Mapping as ORM;

/**
 * The Doctrine-mapped checklist, the same shape as the in-memory
 * {@see \haddowg\JsonApiBundle\Tests\Functional\App\Composite\Checklist}. Not
 * `final` so Doctrine may proxy it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'checklist')]
class ChecklistEntity
{
    /**
     * @param list<mixed>               $tags
     * @param array<string, mixed>      $settings
     * @param array<string, mixed>|null $options
     */
    public function __construct(
        #[ORM\Id]
        #[ORM\GeneratedValue(strategy: 'AUTO')]
        #[ORM\Column]
        public ?int $id = null,
        #[ORM\Column]
        public bool $done = false,
        #[ORM\Column]
        public int $count = 0,
        #[ORM\Column]
        public string $title = '',
        #[ORM\Column(type: 'json')]
        public array $tags = [],
        #[ORM\Column(type: 'json')]
        public array $settings = [],
        #[ORM\Column(nullable: true)]
        public ?string $note = null,
        #[ORM\Column(type: 'json', nullable: true)]
        public ?array $options = null,
    ) {}
}
