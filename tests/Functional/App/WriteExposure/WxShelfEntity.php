<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\WriteExposure;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Backs both `wx-shelves` (read-only) and `wx-bins` (no Update), each declaring a
 * to-one `owner` and a to-many `members`.
 */
#[ORM\Entity]
#[ORM\Table(name: 'wx_shelf')]
class WxShelfEntity
{
    /**
     * @var Collection<int, WxPersonEntity>
     */
    #[ORM\ManyToMany(targetEntity: WxPersonEntity::class)]
    #[ORM\JoinTable(name: 'wx_shelf_members')]
    public Collection $members;

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
    ) {
        $this->members = new ArrayCollection();
    }
}
