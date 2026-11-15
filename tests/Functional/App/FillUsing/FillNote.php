<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\FillUsing;

use Doctrine\ORM\Mapping as ORM;

/**
 * A note whose only relation is a setting's id, written by a fillUsing() hook.
 */
#[ORM\Entity]
#[ORM\Table(name: 'fill_note')]
class FillNote
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    #[ORM\Column(type: 'string')]
    public string $name = '';

    #[ORM\Column(type: 'string', nullable: true)]
    public ?string $settingId = null;
}
