<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\FirstPage;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'fp_comment')]
class FpComment
{
    #[ORM\ManyToOne(targetEntity: FpCard::class, inversedBy: 'comments')]
    #[ORM\JoinColumn(nullable: true)]
    public ?FpCard $card = null;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'integer')]
        public ?int $id = null,
        #[ORM\Column(type: 'string')]
        public string $body = '',
        ?FpCard $card = null,
    ) {
        $this->card = $card;
        $card?->comments->add($this);
    }
}
