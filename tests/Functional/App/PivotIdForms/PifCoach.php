<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\PivotIdForms;

use Doctrine\ORM\Mapping as ORM;

/**
 * A coach keyed by a guid its column keeps as written, in capitals for the seeded one.
 */
#[ORM\Entity]
#[ORM\Table(name: 'pif_coach')]
class PifCoach
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'guid')]
        public string $id,
        #[ORM\Column]
        public string $name = '',
    ) {}
}
