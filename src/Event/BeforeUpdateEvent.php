<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Event;

use haddowg\JsonApi\Request\JsonApiRequestInterface;

/**
 * Dispatched before an update persists, after the aggregate {@see BeforeSaveEvent}.
 * The {@see $entity} is the **mutable**, already-hydrated target; {@see $original}
 * is a pre-change snapshot taken before hydration (so a subscriber can diff). A
 * subscriber that throws a {@see \haddowg\JsonApi\Exception\JsonApiExceptionInterface}
 * aborts the update before any commit.
 */
final class BeforeUpdateEvent
{
    public function __construct(
        public readonly string $type,
        public readonly JsonApiRequestInterface $request,
        public readonly object $entity,
        public readonly object $original,
        public readonly string $serverName,
    ) {}
}
