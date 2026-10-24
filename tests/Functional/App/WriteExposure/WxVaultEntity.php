<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\WriteExposure;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'wx_vault')]
class WxVaultEntity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\GeneratedValue(strategy: 'AUTO')]
        #[ORM\Column]
        public ?int $id = null,
        #[ORM\Column]
        public string $name = '',
        #[ORM\ManyToOne(targetEntity: WxPersonEntity::class)]
        #[ORM\JoinColumn(nullable: true)]
        public ?WxPersonEntity $owner = null,
    ) {}
}
