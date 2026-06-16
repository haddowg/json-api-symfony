<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use Doctrine\ORM\Mapping as ORM;

/**
 * The constructor-less instantiation witness: a Doctrine entity whose constructor
 * takes **required** arguments with no defaults, so `new VaultEntity()` would
 * `TypeError`. Create therefore only works if the reference Doctrine persister
 * builds the instance without invoking the constructor (the point of ADR 0029).
 * Not `final` so Doctrine may proxy it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'vault')]
class VaultEntity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\GeneratedValue(strategy: 'AUTO')]
        #[ORM\Column]
        public ?int $id,
        #[ORM\Column]
        public string $secret,
    ) {}
}
