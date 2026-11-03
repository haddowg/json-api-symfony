<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use Doctrine\ORM\Mapping as ORM;

/**
 * The Doctrine-mapped pipeline, the same shape as the in-memory
 * {@see \haddowg\JsonApiBundle\Tests\Functional\App\Composite\Pipeline}: each
 * structured list is one `json` column. Not `final` so Doctrine may proxy it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'pipeline')]
class PipelineEntity
{
    /**
     * @param list<mixed>|null $steps
     * @param list<mixed>|null $stops
     */
    public function __construct(
        #[ORM\Id]
        #[ORM\GeneratedValue(strategy: 'AUTO')]
        #[ORM\Column]
        public ?int $id = null,
        #[ORM\Column]
        public string $name = '',
        #[ORM\Column(type: 'json', nullable: true)]
        public ?array $steps = null,
        #[ORM\Column(type: 'json', nullable: true)]
        public ?array $stops = null,
    ) {}
}
