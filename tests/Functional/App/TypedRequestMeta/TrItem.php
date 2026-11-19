<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\TypedRequestMeta;

use Doctrine\ORM\Mapping as ORM;

/**
 * An item whose resource meta is open: any member, as long as it is an integer.
 */
#[ORM\Entity]
#[ORM\Table(name: 'tr_item')]
class TrItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    #[ORM\Column(type: 'string')]
    public string $name = '';

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    public array $extras = [];
}
