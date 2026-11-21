<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\TypedRequestMeta;

use Doctrine\ORM\Mapping as ORM;

/**
 * A type declaring no meta at all, whose meta stays open both ways.
 */
#[ORM\Entity]
#[ORM\Table(name: 'tr_plain')]
class TrPlain
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    #[ORM\Column(type: 'string')]
    public string $name = '';
}
