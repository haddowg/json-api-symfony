<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A registered listening device — the **app-generated ULID** id-strategy
 * demonstrator. A device record is minted server-side, so its
 * {@see \haddowg\JsonApiBundle\Examples\MusicCatalog\Resource\DeviceResource}
 * declares `Id::make()->ulid()->generated()`: core mints a Crockford-base32
 * {@see \haddowg\JsonApi\Resource\Field\Ulid ULID} (lexicographically sortable, so
 * the natural id order tracks creation time) when a create omits the id. The id is a
 * plain string column with no DB generator — the value is core's generated ULID, set
 * before the persist. Not `final` so Doctrine may proxy it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'device')]
class Device
{
    #[ORM\Id]
    #[ORM\Column]
    public string $id = '';

    #[ORM\Column]
    public string $label = '';
}
