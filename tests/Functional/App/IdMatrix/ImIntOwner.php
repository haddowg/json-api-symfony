<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IdMatrix;

use Doctrine\ORM\Mapping as ORM;

/**
 * An owner keyed by a `integer` id.
 */
#[ORM\Entity]
#[ORM\Table(name: 'im_int_owner')]
class ImIntOwner
{
    /**
     * The notes an in-memory WhereHas walks; Doctrine maps nothing here.
     *
     * @var list<ImNote>
     */
    public array $notes = [];

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'integer')]
        public int $id,
        #[ORM\Column(type: 'string')]
        public string $name = '',
    ) {}
}
