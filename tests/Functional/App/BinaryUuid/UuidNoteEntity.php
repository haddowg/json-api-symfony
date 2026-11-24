<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\BinaryUuid;

use Doctrine\ORM\Mapping as ORM;

/**
 * A note about a label or a shelf, kept in two plain columns. The id column is a string
 * because its subjects' ids differ in kind.
 */
#[ORM\Entity]
#[ORM\Table(name: 'uuid_note')]
class UuidNoteEntity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'integer')]
        public int $id,
        #[ORM\Column(nullable: true)]
        public ?string $subjectType = null,
        #[ORM\Column(nullable: true)]
        public ?string $subjectId = null,
    ) {}
}
