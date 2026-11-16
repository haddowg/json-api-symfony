<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Inbox\Doctrine;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'inb_campaign')]
class InboxCampaignEntity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column]
        public int $id,
        #[ORM\Column]
        public string $name,
    ) {}
}
