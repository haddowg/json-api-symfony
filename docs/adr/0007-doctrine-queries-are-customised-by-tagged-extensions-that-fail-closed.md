# Doctrine queries are customised by tagged extensions applied before the criteria, and fail closed

Base scopes a client must not undo (soft-delete, tenant, published-only) are `DoctrineExtensionInterface` services applied to every query builder the Doctrine layer runs, before the requested criteria, so requested filters can only narrow the scope, counts agree with it and an out-of-scope row is a `404`. Each call receives an `ExtensionContext` (the type, a `QueryPurpose`, and the request when the load has one). `QueryPurpose` is deliberately non-exhaustive and the contract is to apply unconditionally and branch only to exempt a purpose, so a scope fails closed when a new purpose appears instead of silently not applying.

## Consequences

- Related, include, batch, pivot and to-one loads report `FetchRelatedCollection`; primary loads carry no request, so a request-aware extension may only add constraints when one is present.
- `IncludeHydration` is the one purpose an extension opts into rather than out of: it loads related entities by ids that are already scoped, so a filtering extension changes nothing there, and a fetch-join that would break the scoping subquery belongs there.
- Client-overridable default filter values are a declaration concern in core's filter vocabulary, not an extension.
