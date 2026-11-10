<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\LoadedLinkage;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'loaded_linkage_binding')]
class LinkageBinding
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'integer')]
        public int $id,
        #[ORM\Column(type: 'string')]
        public string $name,
        #[ORM\ManyToOne(targetEntity: LinkageTemplate::class, inversedBy: 'bindings')]
        public ?LinkageTemplate $template = null,
        #[ORM\Column(type: 'boolean')]
        public bool $hidden = false,
        #[ORM\Column(type: 'boolean')]
        public bool $detached = false,
    ) {
        $template?->addBinding($this);
    }
}
