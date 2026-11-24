<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IdMatrix;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A link row from a uuid owner to another, keeping the member's id in a `uuid` column.
 */
#[ORM\Entity]
#[ORM\Table(name: 'im_link')]
class ImLink
{
    public function __construct(
        #[ORM\Id]
        #[ORM\GeneratedValue(strategy: 'AUTO')]
        #[ORM\Column]
        public ?int $id = null,
        #[ORM\ManyToOne(targetEntity: ImUuidOwner::class, inversedBy: 'links')]
        #[ORM\JoinColumn(nullable: false)]
        public ?ImUuidOwner $owner = null,
        #[ORM\Column(type: 'string')]
        public string $kind = '',
        #[ORM\Column(type: 'uuid')]
        public ?Uuid $memberKey = null,
    ) {}
}
