<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Event;

use haddowg\JsonApi\Request\JsonApiRequestInterface;

/**
 * Dispatched before a create persists, after the aggregate {@see BeforeSaveEvent}.
 * The {@see $entity} is **mutable** (a set field is persisted); a subscriber that
 * throws a {@see \haddowg\JsonApi\Exception\JsonApiExceptionInterface} aborts the
 * create before any commit.
 */
final class BeforeCreateEvent
{
    public function __construct(
        public readonly string $type,
        public readonly JsonApiRequestInterface $request,
        public readonly object $entity,
        public readonly string $serverName,
    ) {}
}
