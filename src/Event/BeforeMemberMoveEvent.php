<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Event;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;

/**
 * Dispatched before a to-many add or replace moves a related resource to a new
 * parent: once per {@see $member} that currently belongs to another parent
 * ({@see $from}) or to none (`null`). Fired on the relationship endpoints and for
 * relationships set in a create or update body, only when the type's persister
 * reports moves ({@see \haddowg\JsonApiBundle\DataPersister\ReportsMovedMembersInterface}).
 * A subscriber that throws aborts the write before the persister applies it.
 */
final class BeforeMemberMoveEvent
{
    public function __construct(
        public readonly string $type,
        public readonly JsonApiRequestInterface $request,
        public readonly object $parent,
        public readonly RelationInterface $relation,
        public readonly object $member,
        public readonly ?object $from,
        public readonly string $serverName,
    ) {}
}
