<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\WriteTransaction;

use Doctrine\ORM\Mapping as ORM;

/**
 * A row whose id the client names.
 */
#[ORM\Entity]
#[ORM\Table(name: 'tx_tag')]
class TxTag
{
    #[ORM\Id]
    #[ORM\Column]
    public string $id = '';

    #[ORM\Column]
    public string $label = '';
}
