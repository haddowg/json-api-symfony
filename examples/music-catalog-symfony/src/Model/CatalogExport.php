<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Model;

/**
 * A catalogue export — the producer side of the async-write witness. A `POST
 * /catalog-exports` is accepted for asynchronous processing (a `202` pointing at a
 * pollable `export-jobs` resource) rather than committed inline; the finished export
 * is what the completed job's fetch-one redirects to. No Doctrine entity: like
 * {@see Chart}/{@see Country} it is served by a small custom provider + persister.
 */
final class CatalogExport
{
    public function __construct(
        public string $id = '',
        public string $format = '',
        public string $status = '',
    ) {}
}
