<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Event;

use haddowg\JsonApi\Request\JsonApiRequestInterface;

/**
 * Dispatched once an update's target is loaded, before validation and before any of
 * the body's relationships or attributes are applied to it. The {@see $entity} is the
 * resource as stored, so a subscriber deciding whether the caller may change it sees
 * what the caller is changing, not what the body would turn it into. A subscriber that
 * throws aborts the update before anything is applied.
 */
final class BeforeApplyUpdateEvent
{
    public function __construct(
        public readonly string $type,
        public readonly JsonApiRequestInterface $request,
        public readonly object $entity,
        public readonly string $serverName,
    ) {}
}
