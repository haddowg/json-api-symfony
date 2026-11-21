<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ScopedConversions;

use Doctrine\ORM\Mapping as ORM;

/**
 * A link row a journal's `marks` keep in {@see ScMarks}. Its `tone` is a JSON column
 * holding whatever the write left, so a case and a string both show in memory.
 */
#[ORM\Entity]
#[ORM\Table(name: 'sc_mark')]
class ScMark
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    public ?int $pk = null;

    public function __construct(
        #[ORM\Column(type: 'string')]
        public string $journal,
        #[ORM\Column(type: 'string')]
        public string $type,
        #[ORM\Column(type: 'string')]
        public string $id,
        #[ORM\Column(type: 'json', nullable: true)]
        public mixed $tone = null,
    ) {}
}
