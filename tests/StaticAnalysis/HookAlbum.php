<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\StaticAnalysis;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * The Doctrine entity the hook fixtures type their closures on, declared the way an
 * application declares its own.
 */
#[ORM\Entity]
class HookAlbum
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column]
    public string $title = '';

    #[ORM\Column]
    public bool $locked = false;

    /** @var array<string, string>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    public ?array $credits = null;

    #[ORM\ManyToOne(targetEntity: self::class)]
    public ?HookAlbum $parent = null;

    /** @var Collection<int, HookAlbum> */
    #[ORM\OneToMany(targetEntity: self::class, mappedBy: 'parent')]
    public Collection $children;

    public function __construct()
    {
        $this->children = new ArrayCollection();
    }
}
