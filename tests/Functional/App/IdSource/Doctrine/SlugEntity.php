<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IdSource\Doctrine;

use Doctrine\ORM\Mapping as ORM;

/**
 * The generateUsing witness entity: a string id with no DB generator. Its
 * {@see SlugResource} declares `generateUsing()` with a closure that mints the storage
 * key directly (full control — the closure value is set on the id, never decoded). Not
 * `final` so Doctrine may proxy it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'id_source_slug')]
class SlugEntity
{
    #[ORM\Id]
    #[ORM\Column]
    public string $id = '';

    #[ORM\Column]
    public string $title = '';
}
