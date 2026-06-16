<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IdSource\Doctrine;

use Doctrine\ORM\Mapping as ORM;

/**
 * The generated-ULID witness entity: a `ulid()` string id with no DB generator. Its
 * {@see BadgeResource} declares `ulid()->generated()`, so a create that omits the id
 * has core mint a Crockford-base32 ULID. Not `final` so Doctrine may proxy it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'id_source_badge')]
class BadgeEntity
{
    #[ORM\Id]
    #[ORM\Column]
    public string $id = '';

    #[ORM\Column]
    public string $name = '';
}
