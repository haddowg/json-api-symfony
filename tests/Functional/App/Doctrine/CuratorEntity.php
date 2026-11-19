<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use Doctrine\ORM\Mapping as ORM;

/**
 * The Doctrine-mapped curator, the same shape as the in-memory
 * {@see \haddowg\JsonApiBundle\Tests\Functional\App\FieldSelection\Curator}. Not `final`
 * so Doctrine may proxy it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'field_selection_curator')]
class CuratorEntity
{
    /**
     * @param array<string, mixed>|null $stats
     * @param array<string, mixed>|null $office
     */
    public function __construct(
        #[ORM\Id]
        #[ORM\GeneratedValue(strategy: 'AUTO')]
        #[ORM\Column]
        public ?int $id = null,
        #[ORM\Column]
        public string $name = '',
        #[ORM\Column(type: 'json', nullable: true)]
        public ?array $stats = null,
        #[ORM\Column(type: 'json', nullable: true)]
        public ?array $office = null,
    ) {}
}
