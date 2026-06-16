<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use Doctrine\ORM\Mapping as ORM;

/**
 * The Doctrine-mapped `hookOwners` entity backing a {@see HookWidgetEntity}'s
 * to-one `owner` relationship, the Doctrine twin of the in-memory
 * {@see \haddowg\JsonApiBundle\Tests\Functional\App\Hook\HookOwner}. Not `final`
 * so Doctrine may proxy it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'hook_owner')]
class HookOwnerEntity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'integer')]
        #[ORM\GeneratedValue]
        public ?int $id = null,
        #[ORM\Column]
        public string $name = '',
    ) {}
}
