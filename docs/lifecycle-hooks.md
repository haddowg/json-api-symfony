# Lifecycle hooks: author seams around every operation

The bundle drives every JSON:API request through one generic
[`CrudOperationHandler`](../src/Operation/CrudOperationHandler.php) (see
[lifecycle](lifecycle.md)). **Lifecycle hooks** are the author seam *into* that
flow: fixed points before and after each operation where your code runs — to
authorize a request, guard a delete, stamp an audit field, run imperative
validation the declarative bridge can't express, or shape a custom response —
without writing or decorating a handler.

> For **authorization specifically**, the bundle ships a declarative layer built on
> these hooks: declare a Symfony Security expression on `#[AsJsonApiResource(security:
> …)]` and the bundle gates the operation for you. See [authorization](authorization.md);
> reach for a hook directly only when an expression can't express the rule.

There are two equivalent ways to hook in, and you can mix them freely:

1. **A Symfony event subscriber** — listen to the hook event classes. Best for a
   **cross-cutting** concern that spans types (an audit log, an authorization
   gate).
2. **A resource hook method** — implement `ResourceLifecycleHooksInterface` on a
   resource and override the hooks you want. Best for a **per-type** concern that
   belongs with the resource (a delete-guard for *this* type, a default value on
   create). The methods are sugar over the events: a built-in subscriber routes
   each event to the matching method.

> Hooks require `symfony/event-dispatcher` (it ships with `symfony/framework-bundle`,
> so it is present in every Symfony app). Absent a dispatcher the seam is simply
> inert.

## The hook set and firing order

The full set, in the order each fires:

```
serving                                                        (once per request, before any operation)

create:  serving → BeforeSave → BeforeCreate → [persist] → AfterCreate → AfterSave
update:  serving → BeforeApplyUpdate → [apply body] → BeforeSave → BeforeUpdate → BeforeRelationshipCommit (per body relationship) → [commit]  → AfterUpdate → AfterSave
delete:  serving → BeforeDelete → [delete] → AfterDelete
PATCH/POST/DELETE …/relationships/{rel}:
         serving → BeforeRelationshipMutate → [apply] → BeforeRelationshipCommit → [commit] → AfterRelationshipMutate
GET /{type}/{id}:  serving → AfterFetchOne
GET /{type}:       serving → AfterFetchCollection
```

`serving` is a **server-level** gate fired once per request inside
`Server::dispatch()`, *before* the operation runs — the natural place for a
request-wide authorization gate. The aggregate `BeforeSave` / `AfterSave` pair
wraps **both** create and update (a `creating` flag distinguishes them), so a
concern that applies to every write lives in one place; the more specific
`BeforeCreate`/`BeforeUpdate` (and their `After` twins) fire inside that wrap.

`BeforeApplyUpdate` is an event only (no resource hook method): it fires once the
update's target is loaded, before validation or any of the body is applied, carrying
the entity as stored. The bundle's security subscriber evaluates `securityUpdate`
there as well as at `BeforeUpdate`.

`BeforeRelationshipCommit` is an event only as well. It fires once a relationship
change has been applied to the parent and before it commits: on the relationship
endpoints, and once for each relationship a whole-resource update body sets. Throwing
from a subscriber aborts the write.

A create or update whose body carries `data.relationships` also dispatches
`BeforeEmbeddedRelationshipWriteEvent` (an event only, no hook method) once per
relationship, before the persister applies it. The bundle's security subscriber uses
it to enforce a relation's `security(mutate:)`.

### `beforeUpdate` carries the pre-change snapshot

The update *before* hook is the one with an extra argument: its signature is
`beforeUpdate(object $entity, object $original, HookContext $context)`. `$entity` is
the already-hydrated, mutable target the patch has been applied to; `$original` is a
**pre-change snapshot** taken *before* hydration, so a hook can diff old against new
— e.g. record exactly which fields changed, or reject a change to an immutable field:

```php
public function beforeUpdate(object $entity, object $original, HookContext $context): void
{
    \assert($entity instanceof Album && $original instanceof Album);
    if ($entity->isrc !== $original->isrc) {
        throw /* a 422/409 — the ISRC is immutable once set */;
    }
}
```

The same snapshot rides the event: `BeforeUpdateEvent` exposes `$original` alongside
`$entity` (the other before events carry only the entity, since there is nothing yet
to diff against). The other hook methods differ only in what comes before the
`HookContext`:

- `beforeSave` and `afterSave` take `(object $entity, bool $creating, HookContext $context)`.
- `afterFetchCollection` takes `(array $items, HookContext $context)`, the fetched items.
- `beforeRelationshipMutate` and `afterRelationshipMutate` take the parent entity.
- The rest take `(object $entity, HookContext $context)`.

## Two semantics: *before* aborts, *after* replaces

- A **before** hook (`serving`, `BeforeSave`, `BeforeCreate`, `BeforeUpdate`,
  `BeforeDelete`, `BeforeRelationshipMutate`, and the event-only
  `BeforeRelationshipCommit`) receives the entity **mutable** and
  runs *before* the persister commits. Two things you can do:
  - **Mutate the entity** — a field you set is persisted by the ensuing flush.
  - **Abort** — `throw` a core `JsonApiExceptionInterface`. The route-scoped
    [`ExceptionListener`](errors.md) renders it as the status it carries, and
    nothing commits, including anything an earlier before hook wrote (see
    [hooks run inside the write's transaction](#hooks-run-inside-the-writes-transaction)). Use a `403` for an authorization/guard failure, `422` for an
    imperative-validation failure, `409` for a conflict.
- An **after** hook (`AfterSave`, `AfterCreate`, `AfterUpdate`, `AfterDelete`,
  `AfterRelationshipMutate`, `AfterFetchOne`, `AfterFetchCollection`) runs
  **post-commit** and may **replace** the response value object (custom-action
  shaping). On the event, call `setResponse(...)`; on a resource method, `return`
  the replacement (or `null` to keep the handler's response). A replacement
  `DataResponse` that renders other resources has their relations loaded through
  their providers as a read would, so its linkage and `included` are scoped the same.

`serving` is before-only — it carries no response. Request-wide response shaping
belongs to the per-operation after hooks.

`AfterFetchCollectionEvent` also exposes `$paginator`, the paginator that paged the
collection, already resolved for the request. A `MultiPaginator` arrives as the
child the client selected, so `$event->paginator->kind()` names the concrete
strategy (`page`, `offset`, `cursor` and so on). It is `null` when no pagination
ran, either because the resource's `pagination()` returned `null` or because a
singular filter collapsed the collection. The resource hook method doesn't receive
it, so subscribe to the event when a response replacement depends on it.

## Hooks run inside the write's transaction

Every create, update, delete and relationship write runs in one transaction on its
type's persister, when the persister implements
[`TransactionalDataPersisterInterface`](data-layer.md#optional-persister-capabilities)
as the Doctrine and in-memory ones do. The transaction opens before the write's first
step and commits once the persister has written:

| Write | Inside the transaction |
| --- | --- |
| create | the body's relationships, hydration, entity validation, `BeforeSave`, `BeforeCreate`, the persister's `create()` |
| update | `BeforeApplyUpdate`, validation, the body's relationships, hydration, entity validation, `BeforeSave`, `BeforeUpdate`, `BeforeRelationshipCommit`, the persister's `update()` |
| delete | `BeforeDelete`, then the persister's `delete()` or the resource's [`DeletesEntityInterface::delete()`](doctrine.md#deleting-without-the-persister) |
| relationship endpoints | `BeforeRelationshipMutate`, the apply, entity validation, `BeforeRelationshipCommit` |

A before hook that writes joins the transaction. A flush it makes on the same connection
is undone if a later hook, a validation or the persister's own write throws, so a hook
that has to flush early, for example to give a new row its id, needs no transaction of
its own to stay undoable. The after hooks run once the transaction has committed: what
they read is durable, and a throw from one fails the response without undoing the write.
The response is rendered after the commit too, so a failure while serializing it returns an
error with the write already stored.

There is no switch to turn this off. Inside an [Atomic Operations](atomic-operations.md)
batch the batch's transaction covers the write instead, and a persister that can't
transact runs the write without one. [Custom actions](actions.md#transactions) called
with `POST`, `PATCH`, `PUT` or `DELETE` run in one too.

A hook may still open its own transaction. It nests inside the write's: on Doctrine it
becomes a savepoint, and committing it closes that level only. Open it on the
`EntityManager` or its connection, which the rollback below handles in full; a hook that
calls `beginTransaction()` on the persister instance itself must also close it, because the
persister can't tell that level from the write's own. If the write fails, the
rollback also closes a transaction the hook opened and has not committed, so the
connection is never left mid-transaction for the next request a worker serves. On
Doctrine the rollback also clears the `EntityManager`, which detaches every entity the
request had loaded. When the failure is a flush, Doctrine has already closed the
manager; Symfony's `kernel.reset` gives the next request a fresh one.

A rollback undoes only what went through the connection. A message dispatched to a
queue, an email sent or an HTTP call made from a before hook has already happened. Do
that work in an after hook, or send messages through a transport that writes to the same
database connection, such as Symfony Messenger's Doctrine transport, so the rollback
discards them too.

## Mechanism 1 — an event subscriber (cross-cutting)

Every hook is a plain event under
[`haddowg\JsonApiBundle\Event`](../src/Event). Subscribe with an ordinary Symfony
`EventSubscriberInterface` (autoconfigured — no manual tag):

```php
<?php

declare(strict_types=1);

namespace App\JsonApi;

use haddowg\JsonApiBundle\Event\AfterSaveEvent;
use haddowg\JsonApiBundle\Event\ServingEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class AuditSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly AuditLog $log) {}

    public static function getSubscribedEvents(): array
    {
        return [
            ServingEvent::class => 'onServing',
            AfterSaveEvent::class => 'onAfterSave',
        ];
    }

    public function onServing(ServingEvent $event): void
    {
        // A request-wide gate: throw to abort before any operation runs.
        // (The throw is rendered by the ExceptionListener as its status.)
    }

    public function onAfterSave(AfterSaveEvent $event): void
    {
        $verb = $event->creating ? 'created' : 'updated';
        $this->log->record($event->type, $verb, $event->entity);
    }
}
```

Each event carries the context for its point: the resource `type`, the live
`JsonApiRequestInterface` `request`, the `entity` (or `parent` + `relation` +
`linkage` + `mode` for the relationship pair, or `items` for the collection read),
the `serverName`, and `creating` on the save pair. `BeforeUpdateEvent` additionally
carries the pre-change `$original` snapshot (above) for diffing. The after events
expose `setResponse(...)` / `response()`.

## Mechanism 2 — resource hook methods (per-type)

Implement `ResourceLifecycleHooksInterface` on a resource and `use
ResourceLifecycleHooksTrait` for no-op defaults, then override only what you need:

```php
<?php

declare(strict_types=1);

namespace App\Resource;

use haddowg\JsonApi\Exception\AbstractJsonApiException;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApiBundle\Hook\HookContext;
use haddowg\JsonApiBundle\Hook\ResourceLifecycleHooksInterface;
use haddowg\JsonApiBundle\Hook\ResourceLifecycleHooksTrait;
use App\Entity\Album;

final class AlbumResource extends AbstractResource implements ResourceLifecycleHooksInterface
{
    use ResourceLifecycleHooksTrait;

    public static string $type = 'albums';

    public function fields(): array { /* … */ }

    // Stamp a server-owned field on create — the mutation is persisted.
    public function beforeCreate(object $entity, HookContext $context): void
    {
        \assert($entity instanceof Album);
        $entity->createdAt = new \DateTimeImmutable();
    }

    // Guard the delete: a 409 when the album is still referenced.
    public function beforeDelete(object $entity, HookContext $context): void
    {
        \assert($entity instanceof Album);
        if ($entity->tracks->count() > 0) {
            throw new class extends AbstractJsonApiException {
                public function __construct() { parent::__construct('Album still has tracks', 409); }
                public function getErrors(): array { return []; }
            };
        }
    }
}
```

The built-in [`ResourceHookSubscriber`](../src/EventListener/ResourceHookSubscriber.php)
listens to every lifecycle event, resolves the resource for the event's type, and
— when it implements the interface — calls the matching method, passing the entity
and a [`HookContext`](../src/Hook/HookContext.php). The context is a readonly value
object with public properties, not getters: `$context->request`,
`$context->serverName`, `$context->type`, and for the relationship hooks
`$context->relation`, `$context->linkage` and `$context->mode`. The method signatures
are listed [above](#beforeupdate-carries-the-pre-change-snapshot). A before method throws to abort; an
after method returns a response to replace it. A resource that does not implement the
interface (or a bare serializer/hydrator pair with no resource) is untouched.

> **See it in the example app.** The
> [`PlaylistResource`](../examples/music-catalog-symfony/src/Resource/PlaylistResource.php)
> implements `ResourceLifecycleHooksInterface` with a `beforeCreate` (stamps a
> server-owned field, persisted by the ensuing flush) and a `beforeDelete` guard
> (a `409` when the playlist is non-empty); the cross-cutting
> [`AuditLogSubscriber`](../examples/music-catalog-symfony/src/EventListener/AuditLogSubscriber.php)
> subscribes to `ServingEvent`/`AfterSaveEvent`/`BeforeDeleteEvent`/`AfterDeleteEvent`.
> Both are exercised end to end by
> [`LifecycleHooksTest`](../examples/music-catalog-symfony/tests/LifecycleHooksTest.php).

### Building and deleting the model

Two more resource interfaces replace a persister step for one type rather than
wrapping it. `InstantiatesEntityInterface::instantiate()` builds the model a create
hydrates, and `DeletesEntityInterface::delete()` deletes in place of the persister.
See [Instantiation](doctrine.md#instantiation) and
[Deleting without the persister](doctrine.md#deleting-without-the-persister).

## Hooks vs. the other seams

| Seam | Reach | Use it for |
| --- | --- | --- |
| **Lifecycle hooks** (this page) | One point in one operation | Authz, guards, audit, imperative validation, response shaping |
| The [Validator bridge](validation.md) | Declarative `422` before hydration | Field rules core can express (`required`, length, enum, cross-field) |
| A custom [`DataProvider`/`DataPersister`](custom-data-providers.md) | The whole fetch/persist for a type | Non-Doctrine storage, bespoke query scoping |
| [Handler decoration](custom-serializers-hydrators.md) | The entire dispatch | Wholesale replacement of the engine (rare) |

Reach for a hook before decorating the handler: a hook is scoped to exactly the
point you care about and composes with every other hook, where a decorator owns the
whole flow.

**Next:** [Validation bridge →](validation.md)
