<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IdMatrix;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * An owner keyed by a `uuid` id.
 */
#[ORM\Entity]
#[ORM\Table(name: 'im_uuid_owner')]
class ImUuidOwner
{
    /**
     * The notes an in-memory WhereHas walks; Doctrine maps nothing here.
     *
     * @var list<ImNote>
     */
    public array $notes = [];

    /**
     * @var Collection<int, ImLink>
     */
    #[ORM\OneToMany(targetEntity: ImLink::class, mappedBy: 'owner')]
    public Collection $links;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'uuid')]
        public Uuid $id,
        #[ORM\Column(type: 'string')]
        public string $name = '',
    ) {
        $this->links = new ArrayCollection();
    }
}
