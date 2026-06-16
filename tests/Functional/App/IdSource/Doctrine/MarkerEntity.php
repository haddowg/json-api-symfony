<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IdSource\Doctrine;

use Doctrine\ORM\Mapping as ORM;

/**
 * The generated-UUID / allowClientId witness entity: a `uuid()` string id with no DB
 * generator. Its {@see MarkerResource} declares `allowClientId()->generated()`, so a
 * create may carry a client UUID (validated against the format) or omit it (core mints
 * a v4 UUID). It is also the linkage-format target: its `uuid()` id constraint is what a
 * {@see CounterEntity}'s `marker` linkage id is validated against. Not `final` so
 * Doctrine may proxy it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'id_source_marker')]
class MarkerEntity
{
    #[ORM\Id]
    #[ORM\Column]
    public string $id = '';

    #[ORM\Column]
    public string $name = '';
}
