<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

/**
 * A plain `beacons` model for the operation-exposure witness (ADR 0025): a
 * routed standalone type whose serializer ({@see BeaconSerializer}) opens only
 * {@see \haddowg\JsonApiBundle\Operation\Operation::FetchOne}, so only
 * `GET /beacons/{id}` is routed — a serialize-only type made fetchable by an
 * operations allow-list.
 */
final class Beacon
{
    public function __construct(
        public string $id = '',
        public string $name = '',
    ) {}
}
