<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\WriteTransaction;

use Doctrine\ORM\Mapping as ORM;

/**
 * The row a lifecycle hook writes on the side, so a test can see whether that write survived.
 */
#[ORM\Entity]
#[ORM\Table(name: 'tx_note')]
class TxNote
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column]
    public string $body = '';

    /**
     * Cascades detach as well, so detaching a note one at a time would detach its user too.
     */
    #[ORM\ManyToOne(targetEntity: TxUser::class, cascade: ['persist', 'detach'])]
    #[ORM\JoinColumn(name: 'created_by_id')]
    public ?TxUser $createdBy = null;
}
