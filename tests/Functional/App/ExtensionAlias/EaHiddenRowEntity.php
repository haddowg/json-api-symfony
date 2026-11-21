<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ExtensionAlias;

use Doctrine\ORM\Mapping as ORM;

/**
 * Hides the row `$rowId` of the type `$kind` from every query for that type.
 */
#[ORM\Entity]
#[ORM\Table(name: 'ea_hidden_row')]
class EaHiddenRowEntity
{
    public function __construct(
        #[ORM\Column]
        public string $kind,
        #[ORM\Column]
        public int $rowId,
        #[ORM\Id]
        #[ORM\GeneratedValue(strategy: 'AUTO')]
        #[ORM\Column]
        public ?int $id = null,
    ) {}
}
