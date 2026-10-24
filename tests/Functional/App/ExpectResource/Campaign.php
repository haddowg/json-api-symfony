<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ExpectResource;

use Doctrine\ORM\Mapping as ORM;

/**
 * One entity behind two JSON:API types (`campaigns` and `campaign-summaries`).
 */
#[ORM\Entity]
#[ORM\Table(name: 'expect_campaign')]
class Campaign
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column]
        public ?int $id = null,
        #[ORM\Column]
        public string $title = '',
        #[ORM\Column]
        public int $budget = 0,
    ) {}
}
