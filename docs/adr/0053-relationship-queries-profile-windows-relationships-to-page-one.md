# The Relationship Queries profile windows each relationship to page 1

The bundle wires core's Relationship Queries profile (core ADR 0058): a client that
negotiates the profile URI (`https://haddowg.github.io/json-api/profiles/relationship-queries/`) in
the `Accept` `profile` media-type parameter may filter and sort a **relationship's**
linkage from the PRIMARY request, addressing the relationship by its include path via
the `relatedQuery[<path>][sort|filter]` family (or the `rQ` shorthand; on a conflict
the canonical `relatedQuery` wins). The `ServerFactory` registers the profile with
`Server::withProfile()` so the response advertises it (the `Content-Type` `profile`
param + `links.profile`), and the relatedQuery parse is otherwise gated entirely on
negotiation — when the profile is not negotiated the params are ignored and rendering
is exactly as before.

**The endpoint is reused, not reimplemented.** A `RelationshipWindowBatcher` runs
before render over the fetched page of parents (next to the slice-1
`RelationCountBatcher` and the include preloader). For each rendered to-many relation
under the profile it reads the relation's `RelatedQuery` (its sort + filter), resolves
the page-1 paginator (relation → related resource → server default), and applies the
relatedQuery sort/filter through the provider's existing
`DataProviderInterface::fetchRelatedCollection()` seam — over the SAME merged
vocabulary the related-collection endpoint uses (the related resource's filters/sorts
merged with the relation's own scoped `withFilters()`/`withSorts()`, core ADR 0051),
so an unknown key is the endpoint's same `400` and the count-free vs countable page
distinction (slice 1) is reused verbatim. Page is **out** for includes: the synthetic
fetch is pinned to page 1, so the `sort` SELECTS which members land on the always-page-1
include.

**Path/cardinality validation is the host's (core ADR 0058 delegates it).** Before
windowing, the batcher checks every `relatedQuery`/`rQ` path the client addressed
(`JsonApiRequestInterface::getRelatedQueryPaths()`) against the primary type's
windowable relations: an unknown relationship path or a to-one path used for this
list op is the related-collection endpoint's same `400`, with `source.parameter` the
canonical profile form (`relatedQuery[<path>]`) — never a silent no-op. The bundle
addresses only the **top-level** relations of the request's primary resource, so a
dotted path (`relatedQuery[parent.child]`, legal family grammar) addressing a relation
of an *included* resource resolves to no top-level relation and is rejected as an
unknown path; address such a relation at its own endpoint. Nested dotted-path
windowing down the include chain is out of scope for this slice.

The portable per-parent loop is the strategy on both providers (the in-memory
witness reads the related set off each parent in PHP; the Doctrine provider scopes its
existing per-parent query — the `editors` many-to-many takes the IN-subquery scope) — a
single windowed native batch (`ROW_NUMBER() OVER (PARTITION BY …)` where the platform
supports it) is a deferred optimization, not a correctness requirement.

**The linkage is windowed by writing page 1 back onto the parent.** Core renders a
relationship's linkage `data` from the value read off the parent
(`AbstractRelation::relatedValue`), and the `RelationshipPagination` seam (core ADR 0058)
supplies only the relationship-object pagination LINKS, not the data. So to make the
rendered linkage (and the `included` resources) BE page 1, the batcher writes the
windowed page back onto each parent's relation column — wrapped to match the column's
container (a Doctrine `Collection` property cannot take a raw array, so the page is an
`ArrayCollection`). Each relation is windowed from a per-column snapshot taken before
any sibling mutates it, so a relation reads the original related set; where two distinct
relations alias one storage column (e.g. a default `comments` and a paginated
`pagedComments` over the same association) the write-back is last-writer-wins on that
shared column — the documented boundary (a real entity maps each to-many to a distinct
association, so it never collides). The batcher builds a `RelationshipPagination` (the
page + the plain-form `sort=…&filter[…]=…` query string from
`RelatedQuery::toPlainQueryString()`), keyed by parent object identity then relation
name in a `WindowedRelationshipPagination`, which the handler swaps into the memoized
`Server`'s pagination seam through a stable `RequestScopedRelationshipPagination` holder
— exactly as the count seam is threaded, and `ResetInterface` for the same long-lived
container reason. Core then renders the relationship object's `first`/`prev`/`next`
(+`last` only when the relation is `countable()`, preserving the slice-1 distinction) in
the spec's PLAIN form against the relationship-linkage endpoint — never the profile's
`relatedQuery[…]` form, which only addresses a relationship from a parent request.
