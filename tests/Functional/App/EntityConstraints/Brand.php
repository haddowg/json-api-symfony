<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\EntityConstraints;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BrandRepository::class)]
#[ORM\Table(name: 'constrained_brand')]
class Brand
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    #[ORM\Column(type: 'string')]
    public string $name = '';

    #[ORM\Column(type: 'string', nullable: true)]
    public ?string $label = null;

    #[ORM\Column(type: 'boolean')]
    public bool $archived = false;
}
