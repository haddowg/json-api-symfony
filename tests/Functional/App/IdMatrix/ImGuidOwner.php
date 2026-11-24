<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IdMatrix;

use Doctrine\ORM\Mapping as ORM;

/**
 * An owner keyed by a `guid` id.
 */
#[ORM\Entity]
#[ORM\Table(name: 'im_guid_owner')]
class ImGuidOwner
{
    /**
     * The notes an in-memory WhereHas walks; Doctrine maps nothing here.
     *
     * @var list<ImNote>
     */
    public array $notes = [];

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'guid')]
        public string $id,
        #[ORM\Column(type: 'string')]
        public string $name = '',
    ) {}
}
