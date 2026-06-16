# Declarative authorization: security expressions on a resource

The bundle ships an optional **declarative authorization** layer (bundle ADR 0043):
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
)]
final class AlbumResource extends AbstractResource { /* … */ }
```

| Parameter | Gates | Subject `object` | Default |
| --- | --- | --- | --- |
| `security` | every gated operation | (the operation's subject) | — |
| `securityCreate` | `POST /{type}` | the **hydrated** entity (post-denormalization) | `security` |
| `securityUpdate` | `PATCH /{type}/{id}` **and** relationship mutation | the loaded, changed entity (the **parent** for a relationship mutation) | `security` |
| `securityDelete` | `DELETE /{type}/{id}` | the loaded entity | `security` |
| `securityRead` | `GET /{type}/{id}` | the loaded entity | `security` |

A parameter that resolves to `null` (no override **and** no `security` default)
leaves that operation **ungated** by this layer. So `security: null,
securityDelete: "is_granted('ROLE_ADMIN')"` gates only delete.

## What is — and is not — gated

- **Writes are gated at the *before* hook**, so a denial throws before the persister
  runs: no row is created, updated, or deleted, and no relationship is mutated. The
  store is left exactly as it was.
- **A single read (`GET /{type}/{id}`) is gated** at `AfterFetchOne`, against the
  loaded entity — so `is_granted('VIEW', object)` can hide an individual resource.
- **Collection reads are *not* gated** by this layer. Row-level read authorization
  belongs in the query — scope the collection with a
  [Doctrine extension](custom-data-providers.md) (or a custom provider) so forbidden
  rows simply never appear (a `404` for a hidden id, not a `403`). A single
  all-or-nothing gate on a collection would be the wrong tool.

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

## How a denial renders

A denied expression throws `AccessDeniedException`; the route-scoped
[`ExceptionListener`](errors.md) maps it to a JSON:API error document:

- **`403 Forbidden`** when the request is authenticated but the expression is false;
- **`401 Unauthorized`** when the request is unauthenticated (authentication would
  unlock the operation) — mirroring Symfony's own access-denied handling — and for any
  `AuthenticationException` the firewall surfaces.

Both render as `application/vnd.api+json` with the standard `errors[].status` /
`errors[].title`, exactly like every other bundle error.

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
