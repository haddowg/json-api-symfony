<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\PivotKeys;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Somewhere a template's placeholder is rendered. Pivot rows link to targets, so a
 * definition shared by two templates can be used on one and unused on the other.
 */
#[ORM\Entity]
#[ORM\Table(name: 'pk_placeholder_target')]
class PkPlaceholderTargetEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    public Uuid $id;

    public function __construct(
        #[ORM\Column]
        public string $name = '',
    ) {
        $this->id = Uuid::v7();
    }
}
