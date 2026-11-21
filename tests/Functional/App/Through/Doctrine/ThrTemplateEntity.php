<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Through\Doctrine;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'thr_template')]
class ThrTemplateEntity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column]
        public int $id,
        #[ORM\Column]
        public string $name,
    ) {}
}
