<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\EntityHooks;

use Doctrine\ORM\Mapping as ORM;

/**
 * Its constructor records that it ran, which the persister's constructor-less
 * instantiation would skip.
 */
#[ORM\Entity]
#[ORM\Table(name: 'hooked_note')]
class HookedNote
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    #[ORM\Column(type: 'string')]
    public string $createdVia;

    #[ORM\Column(type: 'boolean')]
    public bool $deleted = false;

    public function __construct(
        #[ORM\Column(type: 'string')]
        public string $body,
    ) {
        $this->createdVia = 'constructor';
    }
}
