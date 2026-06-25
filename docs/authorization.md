# Declarative authorization: security expressions on a resource

The bundle ships an optional **declarative authorization** layer:
a resource declares Symfony Security
[expressions](https://symfony.com/doc/current/security/expressions.html) on its
`#[AsJsonApiResource]` attribute, and the bundle evaluates them at the right
[lifecycle hook](lifecycle-hooks.md) for each operation — denying with a JSON:API
`403` (or `401` when unauthenticated) **before** any persistence. It is the
API-Platform-style `security:` convenience built on the hook keystone, so you get
per-operation, per-object authorization without writing a subscriber or a handler.

> This layer is **opt-in and optional**. It activates only when `symfony/security-core`
> and `symfony/expression-language` are installed, and only gates when a firewall is
> configured. A resource that declares no `security` is never affected. For the lower
> level — placing a firewall in front of JSON:API routes, `access_control`, and how
> firewall failures render — see [security and deployment](security.md).

## Declaring expressions

Add any of five expressions to the attribute. Each is an ExpressionLanguage string
evaluated in Symfony's security context (the variables `user`, `object`, `request`,
`token`, `roles` and the functions `is_granted()`, `is_authenticated_fully()`, …):

```php
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(
    entity: Album::class,
    // The default, applied to every gated operation unless overridden:
    security: "is_granted('ROLE_USER')",
    // Per-operation overrides (each falls back to `security` when null):
    securityCreate: "is_granted('ROLE_ADMIN')",
    securityUpdate: "is_granted('EDIT', object)",
    securityDelete: "is_granted('ROLE_ADMIN')",
    securityRead:   "is_granted('VIEW', object)",
    securityList:   "is_granted('ROLE_USER')",
)]
final class AlbumResource extends AbstractResource { /* … */ }
```

| Parameter | Gates | Subject `object` | Default |
| --- | --- | --- | --- |
| `security` | every operation (incl. the collection) | (the operation's subject) | — |
| `securityCreate` | `POST /{type}` | the **hydrated** entity (post-denormalization) | `security` |
| `securityUpdate` | `PATCH /{type}/{id}` **and** relationship mutation | the loaded, changed entity (the **parent** for a relationship mutation) | `security` |
| `securityDelete` | `DELETE /{type}/{id}` | the loaded entity | `security` |
| `securityRead` | `GET /{type}/{id}` (single) | the loaded entity | `security` |
| `securityList` | `GET /{type}` (collection) | **none** — evaluated before the query (use a role/attribute check) | `security` |

A parameter that resolves to `null` (no override **and** no `security` default)
leaves that operation **ungated** by this layer. So `security: null,
securityDelete: "is_granted('ROLE_ADMIN')"` gates only delete.

### Documentation-only `true` / `false`

Each of the six parameters also accepts a **bool** instead of an expression — a
*documentation-only* declaration that shapes the OpenAPI document without adding a
runtime gate (only an expression is enforced):

- **`true`** — the operation is documented as **secured** (OpenAPI `security` + a
  `401` response) **without** a bundle-evaluated expression. Use it when an external
  Symfony **firewall** (not this layer) enforces the auth, so the document still
  reflects it.
- **`false`** — the operation is documented as **public** (an operation-level
  `security: []` and **no** `401`), overriding the document-level default security
  regardless of what it is. Use it for a genuinely open operation under an otherwise
  authenticated API.

```php
#[AsJsonApiResource(
    entity: Artist::class,
    securityRead: false,             // GET /artists/{id} is public — overrides the default
    securityCreate: true,            // documented secured (the firewall enforces it)
    securityUpdate: "is_granted('EDIT', object)", // enforced AND documented secured
)]
```

A bool is terminal — it does **not** fall back to the `security` default. The OpenAPI
`401`/`security` for an operation reflect its *effective* security: its own
declaration, or the document-level default (`json_api.openapi.security
.default_requirement`) when it inherits — so an API behind a firewall, configured with
only that default, advertises `security` + `401` on every operation, and `false` is
how you carve out the public ones (`securityRead: false` for the single read,
`securityList: false` for the collection).

## What is — and is not — gated

- **Writes are gated at the *before* hook**, so a denial throws before the persister
  runs: no row is created, updated, or deleted, and no relationship is mutated. The
  store is left exactly as it was.
- **A single read (`GET /{type}/{id}`) is gated** at `AfterFetchOne`, against the
  loaded entity — so `is_granted('VIEW', object)` can hide an individual resource.
- **The collection read (`GET /{type}`) is gated** by `securityList`, **before** the
  query (a `BeforeFetchCollection` hook), with **no** subject — an all-or-nothing
  blanket gate (use a role/attribute check like `is_granted('ROLE_ADMIN')`). A denied
  caller never triggers the query. Because the catch-all `security` cascades to it, a
  resource that declares `security:` gates its collection too unless `securityList`
  overrides it (e.g. `securityList: false` to keep the collection public, or a role
  check where the default is a per-object `is_granted('EDIT', object)` that cannot
  apply to a whole collection).
- **Collection reads are *not* row-filtered** by this layer — the gate is
  all-or-nothing. For row-level read authorization (each caller sees a different
  subset), scope the collection with a [Doctrine extension](custom-data-providers.md)
  (or a custom provider) so forbidden rows simply never appear (a `404` for a hidden
  id, not a `403`). Use `securityList` to blanket-block; use a query scope to filter.

## Narrowing the query surface per request

A resource is an ordinary container service (it can take constructor dependencies —
see [resources](resources.md)), and its `filters()`, `sorts()` and per-relation
include allow-list are read **fresh on every request**. So you can make the
*queryable surface itself* depend on the caller: inject `Security` (or `RequestStack`)
and return a narrower vocabulary for an unprivileged client.

```php
final class AlbumResource extends AbstractResource
{
    public function __construct(private readonly Security $security) {}

    public function filters(): array
    {
        $filters = [Where::make('title'), Where::make('releasedAt')];
        if ($this->security->isGranted('ROLE_ADMIN')) {
            $filters[] = Where::make('internalNotes'); // admins only
        }

        return $filters;
    }
}
```

A filter or sort key not in the returned set is rejected with a `400` exactly as an
unknown key would be — so dropping `internalNotes` for a non-admin means their
`filter[internalNotes]=…` is *refused*, not silently ignored. The same technique
narrows `sorts()` and the include allow-list. This is request-time **vocabulary**
narrowing — the Laravel `forget`/`notSupported` equivalent — and it composes with
row-level scoping (a [Doctrine extension](custom-data-providers.md) that hides
forbidden *rows*) and with the declarative `security` gate above.

## Per-object authorization with a Voter

Because the operation's entity is passed as `object`, an expression like
`is_granted('EDIT', object)` delegates straight to an ordinary Symfony
[Voter](https://symfony.com/doc/current/security/voters.html):

```php
final class AlbumVoter extends Voter
{
    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === 'EDIT' && $subject instanceof Album;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        return $subject->getOwner() === $token->getUserIdentifier();
    }
}
```

Register the Voter as a service (autoconfiguration tags it
`security.voter`) and `securityUpdate: "is_granted('EDIT', object)"` lets only an
album's owner update it — everyone else gets a `403`, an unauthenticated client a
`401`.

> **See it in the example app.** The
> [`PlaylistResource`](../examples/music-catalog-symfony/src/Resource/PlaylistResource.php)
> declares `securityUpdate: "is_granted('EDIT', object)"` (owner-only) and
> `securityDelete: "is_granted('ROLE_ADMIN')"` (admin-only); the
> [`PlaylistOwnerVoter`](../examples/music-catalog-symfony/src/Security/PlaylistOwnerVoter.php)
> backs the `EDIT` gate. The full surface — owner-updates,
> non-owner-`403`/unauthenticated-`401`, the relationship-mutation gate against the
> parent, and admin-delete — is exercised by
> [`AuthorizationTest`](../examples/music-catalog-symfony/tests/AuthorizationTest.php).

## Per-relation security

By default a relationship's endpoints are authorized by the **parent** resource: the
related (`GET /{type}/{id}/{rel}`) and linkage (`GET …/relationships/{rel}`) reads ride
the parent's `securityRead`, and a relationship mutation rides its `securityUpdate`. To
authorize a single relationship **independently** — more *or* less permissive than the
resource it hangs off — declare `security()` on the relation:

```php
use haddowg\JsonApi\Resource\Field\BelongsTo;

public function fields(): array
{
    return [
        Id::make(),
        Str::make('name'),
        // This relation's reads are public and its mutation is admin-only, regardless
        // of how the owning resource is gated.
        BelongsTo::make('billingAccount', 'billing-accounts')
            ->security(
                read: false,
                mutate: "is_granted('MANAGE_BILLING', object)",
            ),
    ];
}
```

- **`read`** governs the related and relationship read endpoints; **`mutate`** governs
  relationship mutation (`PATCH`/`POST`/`DELETE …/relationships/{rel}`).
- Each accepts the same `string|bool|null` as the resource-level keys: an expression is
  **enforced** (against the **parent** resource as `object`) and documented secured;
  `true`/`false` are [documentation-only](#documentation-only-true--false); `null`
  (the default) **inherits** the parent's `securityRead` / `securityUpdate`.
- A declared value **replaces** the parent's gate for that relation, so a public
  resource can carry one privileged relationship, or a read-gated resource one
  openly-readable one. The OpenAPI document reflects the override on the relation's
  operations.

> **Subject is the parent.** A relationship hangs off its owner, so the expression is
> evaluated against the loaded **parent** entity as `object` — the same subject a
> relationship mutation already uses.

## How a denial renders

A denied expression throws `AccessDeniedException`; the route-scoped
[`ExceptionListener`](errors.md) maps it to a JSON:API error document:

- **`403 Forbidden`** when the request is authenticated but the expression is false;
- **`401 Unauthorized`** when the request is unauthenticated (authentication would
  unlock the operation) — mirroring Symfony's own access-denied handling — and for any
  `AuthenticationException` the firewall surfaces.

Both render as `application/vnd.api+json` with the standard `errors[].status` /
`errors[].title`, exactly like every other bundle error.

## Request-aware predicates: lightweight per-caller authz

Separate from the firewall/voter path, the **field and relation builders** carry a
family of request-aware predicates — a learnable, uniform way
to say "this field/verb is restricted *for this caller*" without writing a voter or
an expression. Each predicate is a closure that returns **`true` when the restriction
applies**, and the family is consistent across reading, writing and relationships:

| Builder | Signature | Restriction when the closure returns `true` |
| --- | --- | --- |
| `hidden(fn)` | `fn($model, $request)` | the attribute is omitted from the response |
| `writeOnly(fn)` | `fn($request)` | accepted on write, never rendered |
| `readOnly(fn)` / `readOnlyOnCreate(fn)` / `readOnlyOnUpdate(fn)` | `fn($request)` | ignored on write (never hydrated, never validated) |
| `cannotReplace(fn)` / `cannotAdd(fn)` / `cannotRemove(fn)` | `fn($model, $request)` | the relationship verb is `403` |
| `cannotBeIncluded(fn)` | `fn($model, $request)` | `?include` naming it is `400` |
| `when(fn, …)` | `fn($value, $request)` | the wrapped validation rules apply (e.g. `required()` per caller) |

```php
use haddowg\JsonApi\Request\JsonApiRequestInterface;

Str::make('secret')->hidden(
    static fn(mixed $model, JsonApiRequestInterface $request): bool
        => $request->getHeaderLine('X-Role') !== 'admin',
)
```

The request is a PSR-7 `ServerRequestInterface`, so a predicate can read a header, a
query param or anything you put on the request — no security plumbing required (though
nothing stops a predicate from consulting an injected service). These execute on
**both** providers identically and compose with the firewall/voter layer above: use
the predicates for per-field visibility and per-caller verb gating, and the
`security:` expressions for entity-level allow/deny.

> **Static getters describe the *superset*.** A closure-declared restriction is not
> *unconditional*, so the static getters (`isHidden()`, `allowsReplace()`, …) report
> the permissive value — which is what the OpenAPI generator reads. A sometimes-hidden
> field still appears in the schema, and a sometimes-prohibited verb is still exposed:
> the generated document is the union of what *any* caller may see or do, by design
> (a runtime, per-request condition cannot be expressed in a cached schema).
>
> **Scope.** The predicates run on the write-document and read/render/include paths.
> Filter-side and entity-level `when()` conditions, pivot-field visibility and
> Map-child visibility are out of scope (they pass a `null` request / stay static).

## Two equivalent layers

This declarative layer is sugar over the [lifecycle hooks](lifecycle-hooks.md). For
authorization that an expression can't express — multi-entity rules, data-dependent
checks, throwing a `409` instead of a `403` — write a `beforeCreate`/`beforeUpdate`/…
hook (or an event subscriber) and throw a `JsonApiExceptionInterface` yourself. The
two compose: a resource can carry a `security` expression *and* a hook method.

## Enabling the layer

```bash
composer require symfony/security-core symfony/expression-language
```

(`symfony/security-bundle`, which brings both, is what you install to get a firewall —
see [security and deployment](security.md).) With a firewall configured, declared
`security` expressions take effect automatically. Without the packages — or without a
firewall — a declared `security` is inert and a resource behaves as if it had none.
