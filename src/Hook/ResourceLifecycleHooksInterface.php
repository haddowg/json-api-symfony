<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Hook;

use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Response\IdentifierResponse;
use haddowg\JsonApi\Response\NoContentResponse;

/**
 * The per-type lifecycle-hook seam: a resource opts in by implementing this
 * interface (and `use`-ing {@see ResourceLifecycleHooksTrait} for no-op
 * defaults), then overriding only the hooks it needs.
 *
 * The hooks are routed by the built-in
 * {@see \haddowg\JsonApiBundle\EventListener\ResourceHookSubscriber}, which
 * listens to every lifecycle event the {@see \haddowg\JsonApiBundle\Operation\CrudOperationHandler}
 * fires, resolves the resource for the event's type, and — when the resource
 * implements this interface — calls the matching method. So the resource methods
 * are sugar over the events: a single dispatch point, no per-type subscriber
 * registration.
 *
 * The interface lives in the **bundle**, not on core's `AbstractResource`, so
 * core stays free of any dependency on the bundle's event/context types: a
 * resource opts in here without core knowing the hooks exist.
 *
 * Semantics:
 *  - a **before** hook (`beforeSave`/`beforeCreate`/`beforeUpdate`/`beforeDelete`/
 *    `beforeRelationshipMutate`) runs with the entity **mutable** and may **abort**
 *    the operation by throwing a
 *    {@see \haddowg\JsonApi\Exception\JsonApiExceptionInterface} — the route-scoped
 *    {@see \haddowg\JsonApiBundle\EventListener\ExceptionListener} renders it (a
 *    `403` guard/authz, a `422` imperative-validation failure, a `409` conflict).
 *    A mutation a before hook makes to the entity is persisted (it runs before the
 *    persister flush);
 *  - an **after** hook (`afterSave`/`afterCreate`/`afterUpdate`/`afterDelete`/
 *    `afterRelationshipMutate`/`afterFetchOne`/`afterFetchCollection`) runs
 *    post-commit and may **replace** the response value object by returning a new
 *    one (custom-action shaping); returning `null` keeps the handler's response.
 *
 * The aggregate `beforeSave`/`afterSave` pair wraps **both** create and update —
 * `$creating` distinguishes which — so a concern that applies to every write
 * (audit, timestamps) lives in one place.
 */
interface ResourceLifecycleHooksInterface
{
    /**
     * Before a create or an update persists, with `$creating` distinguishing the
     * two; the entity is mutable and a throw aborts. Fires before the more
     * specific {@see beforeCreate()}/{@see beforeUpdate()}.
     */
    public function beforeSave(object $entity, bool $creating, HookContext $context): void;

    /**
     * After a create or an update commits, with `$creating` distinguishing the
     * two; may replace the response. Fires after the more specific
     * {@see afterCreate()}/{@see afterUpdate()}.
     */
    public function afterSave(object $entity, bool $creating, HookContext $context): ?DataResponse;

    /**
     * Before a create persists; the entity is mutable and a throw aborts.
     */
    public function beforeCreate(object $entity, HookContext $context): void;

    /**
     * After a create commits; may replace the `201` response.
     */
    public function afterCreate(object $entity, HookContext $context): ?DataResponse;

    /**
     * Before an update persists; the entity is mutable, `$original` is a
     * pre-change snapshot of it, and a throw aborts.
     */
    public function beforeUpdate(object $entity, object $original, HookContext $context): void;

    /**
     * After an update commits; may replace the `200` response.
     */
    public function afterUpdate(object $entity, HookContext $context): ?DataResponse;

    /**
     * Before a delete; the entity is loaded and a throw aborts (a delete guard).
     */
    public function beforeDelete(object $entity, HookContext $context): void;

    /**
     * After a delete commits; may replace the `204` response.
     */
    public function afterDelete(object $entity, HookContext $context): DataResponse|NoContentResponse|null;

    /**
     * Before a relationship-endpoint mutation applies; the parent is mutable and a
     * throw aborts. `$context` carries the relation, parsed linkage and mode.
     */
    public function beforeRelationshipMutate(object $parent, HookContext $context): void;

    /**
     * After a relationship-endpoint mutation commits; may replace the linkage
     * response. `$context` carries the relation, parsed linkage and mode.
     */
    public function afterRelationshipMutate(object $parent, HookContext $context): ?IdentifierResponse;

    /**
     * After a single resource is fetched (`GET /{type}/{id}`); may replace the
     * response.
     */
    public function afterFetchOne(object $entity, HookContext $context): ?DataResponse;

    /**
     * After a collection is fetched (`GET /{type}`); may replace the response.
     *
     * @param list<object> $items the materialized page/collection items
     */
    public function afterFetchCollection(array $items, HookContext $context): ?DataResponse;
}
