<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A genre — the **client-supplied natural-key** id-strategy demonstrator. A genre's
 * identity *is* its slug (`rock`, `trip-hop`), a stable natural key the client owns,
 * so its {@see \haddowg\JsonApiBundle\Examples\MusicCatalog\Resource\GenreResource}
 * declares `Id::make()->requireClientId()`: a create MUST carry its `id`, and a
 * create that omits it is a `403` (`ClientGeneratedIdRequired`). The id is a plain
 * string column with no generator — the store assigns nothing; the supplied key is
 * the primary key. Not `final` so Doctrine may proxy it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'genre')]
class Genre
{
    #[ORM\Id]
    #[ORM\Column]
    public string $id = '';

    #[ORM\Column]
    public string $name = '';
}
