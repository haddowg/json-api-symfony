<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\WriteTransaction;

use Doctrine\ORM\Mapping as ORM;

/**
 * Stands in for the security user: loaded before a write and stamped on the notes written
 * after it, by a listener that blames it the way Gedmo's Blameable does.
 */
#[ORM\Entity]
#[ORM\Table(name: 'tx_user')]
class TxUser
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column(unique: true)]
    public string $name = '';

    #[ORM\ManyToOne(targetEntity: self::class, cascade: ['persist'])]
    #[ORM\JoinColumn(name: 'created_by_id')]
    public ?TxUser $createdBy = null;
}
