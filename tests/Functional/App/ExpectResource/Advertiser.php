<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ExpectResource;

use Doctrine\ORM\Mapping as ORM;

/**
 * The parent entity of the expectResource() resolution fixture; {@see Agency}
 * extends it through single-table inheritance.
 */
#[ORM\Entity]
#[ORM\Table(name: 'expect_advertiser')]
#[ORM\InheritanceType('SINGLE_TABLE')]
#[ORM\DiscriminatorColumn(name: 'kind', type: 'string')]
#[ORM\DiscriminatorMap(['advertiser' => Advertiser::class, 'agency' => Agency::class])]
class Advertiser
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column]
        public ?int $id = null,
        #[ORM\Column]
        public string $name = '',
    ) {}
}
