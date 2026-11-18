<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\TypedMeta;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'tm_card')]
class TmCard
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    #[ORM\Column(type: 'string')]
    public string $title = '';

    #[ORM\Column(type: 'boolean')]
    public bool $done = false;

    #[ORM\ManyToOne(targetEntity: TmBoard::class, inversedBy: 'cards')]
    #[ORM\JoinColumn(nullable: true)]
    public ?TmBoard $board = null;
}
