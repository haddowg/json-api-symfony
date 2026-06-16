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
update:  serving → BeforeSave → BeforeUpdate → [commit]  → AfterUpdate → AfterSave
delete:  serving → BeforeDelete → [delete] → AfterDelete
PATCH/POST/DELETE …/relationships/{rel}:
         serving → BeforeRelationshipMutate → [apply] → AfterRelationshipMutate
GET /{type}/{id}:  serving → AfterFetchOne
GET /{type}:       serving → AfterFetchCollection
```

`serving` is a **server-level** gate (core ADR 0050) fired once per request inside
`Server::dispatch()`, *before* the operation runs — the natural place for a
request-wide authorization gate. The aggregate `BeforeSave` / `AfterSave` pair
wraps **both** create and update (a `creating` flag distinguishes them), so a
concern that applies to every write lives in one place; the more specific
`BeforeCreate`/`BeforeUpdate` (and their `After` twins) fire inside that wrap.

## Two semantics: *before* aborts, *after* replaces

- A **before** hook (`serving`, `BeforeSave`, `BeforeCreate`, `BeforeUpdate`,
  `BeforeDelete`, `BeforeRelationshipMutate`) receives the entity **mutable** and
  runs *before* the persister commits. Two things you can do:
  - **Mutate the entity** — a field you set is persisted by the ensuing flush.
  - **Abort** — `throw` a core `JsonApiExceptionInterface`. The route-scoped
    [`ExceptionListener`](errors.md) renders it as the status it carries, and
    nothing commits. Use a `403` for an authorization/guard failure, `422` for an
    imperative-validation failure, `409` for a conflict.
- An **after** hook (`AfterSave`, `AfterCreate`, `AfterUpdate`, `AfterDelete`,
  `AfterRelationshipMutate`, `AfterFetchOne`, `AfterFetchCollection`) runs
  **post-commit** and may **replace** the response value object (custom-action
  shaping). On the event, call `setResponse(...)`; on a resource method, `return`
  the replacement (or `null` to keep the handler's response).

`serving` is before-only — it carries no response. Request-wide response shaping
belongs to the per-operation after hooks.

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
the `serverName`, and `creating` on the save pair. The after events expose
`setResponse(...)` / `response()`.

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
and a [`HookContext`](../src/Hook/HookContext.php) (the request, server name, type,
and the relation/linkage/mode for the relationship hooks). A before method throws
to abort; an after method returns a response to replace it. A resource that does
not implement the interface (or a bare serializer/hydrator pair with no resource)
is untouched.

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
