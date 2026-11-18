<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IdentifiedMorphGate;

use Doctrine\ORM\Mapping as ORM;

/**
 * A post that stores its subject as a type and id column pair.
 */
#[ORM\Entity]
#[ORM\Table(name: 'gated_post')]
class GatedPost
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'integer')]
        public int $id,
        #[ORM\Column(type: 'string')]
        public string $name,
        #[ORM\Column(type: 'string', nullable: true)]
        public ?string $subjectType = null,
        #[ORM\Column(type: 'integer', nullable: true)]
        public ?int $subjectId = null,
    ) {}
}
