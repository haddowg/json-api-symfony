# Declarative authorization: security expressions on a resource

The bundle ships an optional **declarative authorization** layer:
a resource declares Symfony Security
[expressions](https://symfony.com/doc/current/security/expressions.html) on its
`#[AsJsonApiResource]` attribute, and the bundle evaluates them at the right
[lifecycle hook](lifecycle-hooks.md) for each operation — denying with a JSON:API
`403` (or `401` when unauthenticated) **before** any persistence. It is the
API-Platform-style `security:` convenience built on the hook keystone, so you get
per-operation, per-object authorization without writing a subscriber or a handler.

> This layer is **opt-in**. A security expression needs `symfony/security-core`,
> `symfony/expression-language` and a configured firewall. If any of them is missing,
> the container build fails and names the resource and what to install, so a declared
> expression is never silently skipped. The `true`/`false` values need none of them, and
> a resource that declares no `security` is never affected. For the lower
> level — placing a firewall in front of JSON:API routes, `access_control`, and how
> firewall failures render — see [security and deployment](security.md).

## Declaring expressions

Add any of six expressions to the attribute. Each is an ExpressionLanguage string
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
| `securityUpdate` | `PATCH /{type}/{id}` **and** relationship mutation | the stored entity before the body is applied, then the changed entity; the **parent**, before the change, for a relationship mutation | `security` |
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

- **An update must pass `securityUpdate` twice.** The expression is evaluated first
  against the resource as stored, before the body's attributes or relationships are
  applied, so a caller can't pass an ownership check by setting the owner to
  themselves in the same request. It is evaluated again against the changed resource,
  so a caller can't turn a resource into one the expression would refuse them, such as
  handing it to another user. A relationship mutation (`PATCH`/`POST`/`DELETE
  …/relationships/{rel}`) is checked the same two ways, with the relation's `mutate`
  expression or else `securityUpdate`: against the parent before the change, and
  against the changed parent before it commits. Atomic Operations `update` operations,
  on a resource or a relationship, are gated the same way. A refusal after the change
  rolls it back when the data persister implements `TransactionalDataPersisterInterface`
  (both bundled persisters do); a custom persister that can't transact has already
  applied the relationship change when the second check refuses it.
- **Moving a related resource out of another parent is gated.** On the inverse side
  of a Doctrine one-to-many, adding a member that belongs to another parent moves it.
  The move needs the caller to pass the update gate of the parent it leaves, or of the
  member itself; see [Moving a child between parents](doctrine.md#moving-a-child-between-parents).
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
  relationship mutation (`PATCH`/`POST`/`DELETE …/relationships/{rel}`) and setting
  the relationship in a create or update body (`data.relationships`, Atomic Operations
  `add`/`update` included). In a body it is checked in addition to the resource's
  `securityCreate`/`securityUpdate`, not instead of it, and only when the body carries
  that relationship.
- Each accepts the same `string|bool|null` as the resource-level keys: an expression is
  **enforced** (against the **parent** resource as `object`) and documented secured;
  `true`/`false` are [documentation-only](#documentation-only-true--false); `null`
  (the default) **inherits** the parent's `securityRead` / `securityUpdate`.
- A declared value **replaces** the parent's gate for that relation, so a public
  resource can carry one privileged relationship, or a read-gated resource one
  openly-readable one. A declared `read` also replaces the related type's own gates
  for that relation (see [Relation read permissions](#relation-read-permissions)). The
  OpenAPI document reflects the override on the relation's operations.

> **Subject is the parent.** A relationship hangs off its owner, so the expression is
> evaluated against the loaded **parent** entity as `object` — the same subject a
> relationship mutation already uses.

### The read expression also guards rendered linkage and includes

A relation's `read` expression applies wherever its data is rendered, not just at its
own endpoints. When a resource is fetched, listed or included, each relationship whose
`read` is an expression is checked against that resource. A caller who fails it sees the
relationship's `links` and nothing else: no `data`, no `meta` such as a `?withCount`
total, and nothing added to `included`. An `?include` path through it is dropped for
that parent rather than refused for the request, so in a collection one member may show
the data while another doesn't. A `null` read inherits the parent's read gate, which the
caller already passed to see the parent, so the data renders; `true`/`false` only
document the endpoints, so the data renders too.

### Flattened writes need the related row's update gate

A [flattened `on()` attribute](relationships.md#flattened-on-attributes-and-eager-loading)
writes onto a related row. Before each such write the bundle checks the related type's
`securityUpdate` (or `security`) against that row, the same check a `PATCH` to the row
itself would run, and a refusal is a `403` with nothing saved. Relationships in the
same body are applied first, so when a body re-links the to-one and writes through it
in one go, the check runs against the row it was just linked to. A related type that
declares no update expression lets the write through.

## Related resources follow their own type's gates

A response that shows resources of another type, through `?include`, linkage or a related
endpoint, applies that type's own read gates, so a type can't be read around them, unless
the relation [declares its own read permission](#relation-read-permissions):

- **A single related resource** (a to-one) needs the related type's `securityRead` (or
  `security`) with that resource as `object`, the check `GET /{type}/{id}` makes. A
  resource it refuses reads as absent: the relationship's `data` is `null`, nothing is
  added to `included`, the related and relationship endpoints answer `data: null`, and an
  [`on()`](relationships.md#flattened-on-attributes-and-eager-loading) attribute through
  it reads `null` and refuses a write with `422`, as for a missing resource.
- **A to-many** shows exactly what its query returns, never filtered row by row, so pages
  are full and totals match the rows you can page through. It is shown only when the
  caller passes the `securityList` (or `security`) of every type it relates to, the check
  `GET /{type}` makes. Otherwise it renders only its `links`, with no `data`, no `meta`
  (so no `?withCount` total) and nothing in `included`, and its related and relationship
  endpoints answer `403` (or `401`) as `GET /{type}` would.

Which rows a list holds is decided by the query: use a
[Doctrine extension](custom-data-providers.md) or a scoped provider for rows a caller
should never see in any list. A row a to-one refuses can still appear through a to-many
whose query returns it.

## Relation read permissions

A relation's `security(read: …)` is its **read permission**, and it replaces the related
type's gates for that relation only: `securityList` for a to-many, `securityRead` for a
to-one. A string expression is evaluated with the **parent** as `object`. A bool declares
the relation and enforces nothing, so it skips the related type's gates as it skips the
parent's. With nothing declared, the related type's gates apply as above.

When the permission allows, the relation's data renders under [scoping](#scoping-access-per-user)
alone: linkage, `included`, both endpoints, `?withCount`, the Relationship Queries window
and `on()` attributes through a to-one. When it refuses, the relationship renders only its
`links`, both endpoints answer `403`, and an `on()` attribute through a refused to-one
reads `null` and refuses a write with `422 RELATED_ATTRIBUTE_OWNER_MISSING`. A polymorphic
to-many's permission stands in for every member type at once. Writes are unchanged.

**Exposing a relation of a type the caller can't list.** `securedWidgets` refuses its
collection to a caller with `ROLE_NO_LIST`, so its `partners` to-many is withheld from
them. A sibling relation that declares its own permission shows them the same rows:

```php
#[AsJsonApiResource(
    security: "is_granted('ROLE_USER')",
    securityList: "is_granted('ROLE_USER') and not is_granted('ROLE_NO_LIST')",
)]
final class SecuredWidgetResource extends AbstractResource
{
    public function fields(): array
    {
        return [
            // ...
            HasMany::make('partners', 'securedWidgets')->countable(),
            HasMany::make('exposedPartners', 'securedWidgets')->storedAs('partners')->countable()
                ->security(read: "is_granted('ROLE_USER')"),
        ];
    }
}
```

**Blocking one relation of a type the caller can list.** Any user may list widgets, but
only an admin sees this relation's data:

```php
HasMany::make('blockedPartners', 'securedWidgets')->storedAs('partners')
    ->security(read: "is_granted('ROLE_ADMIN')"),
```

Both run in `RelatedRowReadGateConformance`, on the Doctrine and in-memory kernels.

## Scoping access per user

Permission and scope are different questions.

- **Permission** decides whether data shows at all: `securityList`, `securityRead` and
  a relation's `security(read: …)`. A refusal withholds a whole list or a whole row.
- **Scope** decides which rows exist. It always applies, it can leave zero rows, and it
  is the only thing that should hide individual rows from a list. A scope narrows the
  query, so pages stay full and every count matches the rows a caller can page through.

### With a Doctrine extension

An extension that limits a type's rows to the signed-in user:

```php
use Doctrine\ORM\QueryBuilder;
use haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineExtensionInterface;
use haddowg\JsonApiBundle\DataProvider\Doctrine\ExtensionContext;
use Symfony\Bundle\SecurityBundle\Security;

final class OwnNotesExtension implements DoctrineExtensionInterface
{
    public function __construct(private readonly Security $security) {}

    public function supports(string $type): bool
    {
        return $type === 'ownedNotes';
    }

    public function apply(QueryBuilder $builder, ExtensionContext $context): QueryBuilder
    {
        $alias = $builder->getRootAliases()[0];
        $user = $this->security->getUser();
        if ($user === null) {
            return $builder->andWhere('1 = 0');
        }

        return $builder
            ->andWhere(\sprintf('%s.owner = :own_notes_owner', $alias))
            ->setParameter('own_notes_owner', $user->getUserIdentifier());
    }
}
```

It applies to every query for the type, so it limits:

- `GET /ownedNotes` and its `meta.page.total`;
- every relationship that reaches `ownedNotes`: the related and relationship endpoints'
  pages and totals, linkage, `included`, `?withCount`, and the Relationship Queries
  window and its count.

Folder 1 holds notes 1 and 3 by ada and note 2 by grace. Signed in as ada:

```http
GET /ownedFolders/1?include=notes&withCount=notes
Accept: application/vnd.api+json;profile="https://haddowg.github.io/json-api/profiles/countable/"
```

The response, trimmed to the parts that matter:

```json
{
  "data": {
    "type": "ownedFolders",
    "id": "1",
    "relationships": {
      "notes": {
        "data": [{"type": "ownedNotes", "id": "1"}, {"type": "ownedNotes", "id": "3"}],
        "meta": {"total": 2}
      }
    }
  },
  "included": [
    {"type": "ownedNotes", "id": "1", "attributes": {"owner": "ada"}},
    {"type": "ownedNotes", "id": "3", "attributes": {"owner": "ada"}}
  ]
}
```

`GET /ownedFolders/1/notes?withCount=_self_` answers the same two notes with
`meta.total` 2, and grace sees only note 2 everywhere.

### With a custom scoped provider

For a type that isn't on Doctrine, scope the rows in the provider, before the request's
criteria run, so filters, sorting, pages and counts all work over the caller's rows:

```php
use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApiBundle\DataProvider\AbstractDataProvider;
use haddowg\JsonApiBundle\DataProvider\CollectionCriteria;
use haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider;
use Symfony\Bundle\SecurityBundle\Security;

final class OwnMemosProvider extends AbstractDataProvider
{
    public function __construct(private readonly Security $security) {}

    public function supports(string $type): bool
    {
        return $type === 'memos';
    }

    public function scopesRelatedReads(RelationInterface $relation): bool
    {
        return true;
    }

    public function fetchOne(string $type, string $id): ?object
    {
        foreach ($this->own(Memo::all()) as $memo) {
            if ((string) $memo->id === $id) {
                return $memo;
            }
        }

        return null;
    }

    public function fetchCollection(string $type, CollectionCriteria $criteria): CollectionResult
    {
        return $this->query($this->own(Memo::all()), $criteria);
    }

    public function fetchRelatedCollection(string $relatedType, object $parent, RelationInterface $relation, CollectionCriteria $criteria, JsonApiRequestInterface $request): CollectionResult
    {
        return $this->query($this->own(Memo::heldBy($parent)), $criteria);
    }

    // Scope first, then let the in-memory provider apply the request's criteria.
    private function query(array $memos, CollectionCriteria $criteria): CollectionResult
    {
        return (new InMemoryDataProvider('memos', $memos))->fetchCollection('memos', $criteria);
    }

    private function own(iterable $memos): array
    {
        $user = $this->security->getUser()?->getUserIdentifier();

        return \array_values(\array_filter([...$memos], static fn(mixed $memo): bool => $memo instanceof Memo && $user !== null && $memo->owner === $user));
    }
}
```

Returning `true` from `scopesRelatedReads()` makes the bundle read every relation that
reaches `memos` through this provider, so linkage, `included`, `?withCount` and both
related endpoints follow the same scope. Both examples run in `DoctrineUserScopingTest`
and `InMemoryUserScopingTest`.

## How a denial renders

A denied expression throws `AccessDeniedException`; the route-scoped
[`ExceptionListener`](errors.md) maps it to a JSON:API error document:

- **`403 Forbidden`** when the request is authenticated but the expression is false;
- **`401 Unauthorized`** when the request is unauthenticated (authentication would
  unlock the operation) — mirroring Symfony's own access-denied handling — and for any
  `AuthenticationException` the firewall surfaces.

Both render as `application/vnd.api+json` with the standard `errors[].status` /
`errors[].title`, plus the stable `errors[].code`: `ACCESS_DENIED` for the `403`,
`AUTHENTICATION_REQUIRED` for the `401` (see [errors](errors.md#authentication-and-authorization-failures)).

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

(`symfony/security-bundle`, which brings both, is what you install to get a firewall,
see [security and deployment](security.md).) With a firewall configured, declared
`security` expressions take effect automatically.

If a resource, relation or action declares an expression and the app lacks either
package or the `security.authorization_checker` service (no SecurityBundle firewall),
the build fails. Resource and action expressions fail the container compile; relation
expressions fail the cache warm-up, which runs on every container build. The message
names each declaration and what is missing:

```text
Declared JSON:API security cannot be enforced: symfony/expression-language is not
installed (composer require symfony/expression-language). It is declared on type
"albums" (securityCreate). ...
```

The documentation-only `true` and `false` values are never evaluated, so they build and
project into the [OpenAPI document](openapi.md) without any of these packages. Use
`true` when your own firewall or `access_control` secures an operation, and `false` to
document it as public.
