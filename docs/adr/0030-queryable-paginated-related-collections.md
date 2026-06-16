# The related to-many endpoint is a queryable, paginated collection over a provider seam

`GET /{type}/{id}/{relationship}` for a to-many no longer reads the whole
collection off the parent and renders it unwindowed. It is now a real queryable
collection — honouring `?filter[…]`, `?sort=…` and `?page[…]` against the
**related** type's vocabulary — resolved over a new
`DataProviderInterface::fetchRelatedCollection()` seam (the related-endpoint twin
of `fetchCollection()`). The handler resolves the related type's
`filters()`/`allSorts()` and a per-relation paginator into a `CollectionCriteria`,
asks the related provider to execute it scoped to the parent, and renders a
paginated `RelatedResponse::fromPage()` (else a plain `fromCollection()`),
mirroring the primary collection path. Per-relation default pagination resolves
`relation paginator -> related resource paginator -> server default`.

The two providers execute the seam differently but share the criteria machinery:
the in-memory provider reads the related objects off the parent via the relation
accessor and applies the shared `CriteriaApplier` + array window; the Doctrine
provider scopes a push-down `QueryBuilder` on the **related** repo by the inverse
owning FK (so it never loads the whole collection), keeping the `resource` root
alias so the existing filter/sort handlers work unchanged, then reuses the same
filter/sort/count/window machinery as `fetchCollection`.

The Doctrine push-down's documented boundary: it requires a single-valued inverse
association (the OneToMany case, whose related entity carries the owning foreign
key). When the parent's association is owning-side or many-to-many there is no
single-valued inverse FK to scope by, so the provider throws a clear
`LogicException` naming the relation and related type; paginated many-to-many
related collections are deferred to a custom `DataProvider`.

Builds on core ADRs 0034 (paginated `RelatedResponse::fromPage()`) and 0035
(per-relation paginator: `AbstractRelation::paginate()`/`pagination()`,
`RelationInterface::pagination()`).
