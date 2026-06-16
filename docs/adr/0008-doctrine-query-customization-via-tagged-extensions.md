# Doctrine queries are customized through a tagged extension seam applied before the criteria

Base constraints a client must not be able to undo — soft-delete exclusion,
tenant scoping, published-only — previously required replacing the whole
Doctrine provider for a type, re-owning its window/COUNT execution just to add
one `WHERE`. A `DoctrineExtensionInterface` (tagged, autoconfigured, applied in
descending priority order) now customizes every QueryBuilder the provider
executes, *before* the requested criteria: requested filters can only `AND`
onto the scope, the pre-window COUNT derives from the customized builder so
paginated totals agree with it, and `fetchOne()` runs through the same pipeline
(falling back to `find()` only when no extension supports the type) so an
out-of-scope row is a `404`.

Each application receives an `ExtensionContext` (the type, a `QueryPurpose` and
a nullable `JsonApiRequestInterface`; see ADR 0070) rather than loose arguments,
so a scope can be request-aware on the related/include/batch loads that carry the
request, while staying purpose-driven on the primary loads that do not. The
`QueryPurpose` is deliberately **non-exhaustive**: the write phase adds purposes
for the persister's target loads (an update/delete fetches its entity through this
pipeline, inheriting the scope), and a `FetchRelatedCollection` case (ADR 0070)
distinguishes a related load from the primary collection — and the documented
contract (apply unconditionally, branch only to *exempt* a purpose) means a
scoping rule fails closed when new purposes appear, rather than silently not
applying. This is the storage-specific half of the design: client-overridable
*default* filter values are a declaration concern and belong in core's filter
vocabulary, not in a QueryBuilder hook.
