<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Model;

/**
 * A reference-data country — no Doctrine entity, no Resource. Its rows are sourced
 * from `symfony/intl`'s `Countries` (id = ISO 3166-1 alpha-2 code, name = the
 * localized country name) by the
 * {@see \haddowg\JsonApiBundle\Examples\MusicCatalog\Provider\CountryProvider} and
 * rendered by the standalone
 * {@see \haddowg\JsonApiBundle\Examples\MusicCatalog\Serializer\CountrySerializer}
 * — the simplest "expose arbitrary, non-database data as JSON:API" path: a static
 * custom provider + a standalone serializer, read-only.
 */
final class Country
{
    public function __construct(
        public string $id = '',
        public string $name = '',
    ) {}
}
