<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Model;

/**
 * The request document of the album `reissue` action: the date the album is re-released
 * on. It is never stored; the action applies it onto the album.
 */
final class Reissue
{
    public ?\DateTimeImmutable $releasedAt = null;
}
