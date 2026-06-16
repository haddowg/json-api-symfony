# `DoctrineExtensionInterface::apply()` receives a request-aware `ExtensionContext`

A `DoctrineExtensionInterface` could scope a Doctrine query by type and
`QueryPurpose` but never saw the *request* it was scoping for, so a base
constraint could not branch on a query parameter or header (a per-request
tenant override, a header-gated visibility) — and every relationship/include/batch
load reported the same `FetchCollection` purpose as the primary `GET /{type}`
collection, so a scope could not even tell a primary collection from a related
load of the same type. We replaced the loose `apply(QueryBuilder, string $type,
QueryPurpose $purpose)` signature with `apply(QueryBuilder, ExtensionContext
$context)` — a small `final readonly` value object carrying `type`, `purpose` and
a **nullable** `JsonApiRequestInterface` — and added a `QueryPurpose::FetchRelatedCollection`
case that every related/include/batch/pivot/to-one-match load reports while
serving another type's request. The request is threaded from the provider's read
methods that already carry it (the related call sites) and is `null` on the
primary `FetchOne`/`FetchCollection` loads, whose SPI carries no request — so an
extension branches on it only to *add* a constraint, falling through to its
unconditional base scope when it is absent (the primary fetch stays scoped). This
is a breaking change to a public interface, acceptable pre-1.0; it does not touch
the EXISTS-builder batch rooting (ADR 0061) — the extensions still apply on the
related entity in both batched shapes, now with the context object.
