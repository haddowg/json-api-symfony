<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ScopedConversions;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'sc_journal')]
class ScJournal
{
    public function __construct(
        #[ORM\Id]
        #[ORM\GeneratedValue(strategy: 'NONE')]
        #[ORM\Column(type: 'string')]
        public ?string $id = null,
        #[ORM\Column(type: 'string')]
        public string $name = '',
    ) {}
}
