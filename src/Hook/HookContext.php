<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Hook;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\Mode;
use haddowg\JsonApi\Resource\Field\RelationInterface;

/**
 * The immutable context a per-type lifecycle hook
 * ({@see ResourceLifecycleHooksInterface}) receives: the live JSON:API request,
 * the server name the operation dispatched on, the resource type, and — for the
 * relationship-mutation pair — the {@see RelationInterface} being mutated, the
 * parsed linkage, and the {@see Mode} of the mutation.
 *
 * It is the bundle value object the built-in
 * {@see \haddowg\JsonApiBundle\EventListener\ResourceHookSubscriber} assembles
 * from the dispatched event before routing to the resource's hook method, so a
 * resource's hooks read the request/relation context without depending on the
 * bundle's event classes.
 */
final readonly class HookContext
{
    /**
     * @param object|null $linkage the parsed relationship linkage for a
     *                             relationship-mutation hook (a
     *                             {@see \haddowg\JsonApi\Hydrator\Relationship\ToOneRelationship}
     *                             or {@see \haddowg\JsonApi\Hydrator\Relationship\ToManyRelationship}),
     *                             else null
     */
    public function __construct(
        public JsonApiRequestInterface $request,
        public string $serverName,
        public string $type,
        public ?RelationInterface $relation = null,
        public ?object $linkage = null,
        public ?Mode $mode = null,
    ) {}
}
