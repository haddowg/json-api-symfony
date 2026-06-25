# A relation's `security()` is enforced independently of its parent

A relationship's endpoints (the related read `GET /{type}/{id}/{rel}`, the linkage read
`GET …/relationships/{rel}`, and the mutations `PATCH`/`POST`/`DELETE …/relationships/{rel}`)
were authorized only by the **parent** resource's security: the read endpoints rode the
parent's `securityRead` gate (`AfterFetchOneEvent`) and a mutation rode the parent's
`securityUpdate` gate. There was no way to authorize a single relationship on its own —
yet a public resource may carry one privileged relationship, or a restricted resource
one openly-readable one.

Core ADR 0099 added the declaration: `AbstractRelation::security(read:, mutate:)`, each
`string|bool|null`, overriding the parent's gate (`null` inherits). This ADR is the
bundle's **enforcement** of it:

- **Read.** When a relation declares its own `securityRead`, the handler dispatches a
  relation-scoped **`BeforeFetchRelatedEvent`** / **`BeforeFetchRelationshipEvent`**
  (carrying the loaded parent + the relation) *instead of* the parent's
  `AfterFetchOneEvent`; `ResourceSecuritySubscriber` evaluates the relation's expression
  against the parent. A relation declaring no read security keeps the parent's read gate
  unchanged. To make the override reachable, the read gate now runs **after** the
  relation is resolved (a non-existent/unexposed relation still `404`s first — relation
  names are public API, and parent existence already leaks via `loadParent`'s `404`
  before any gate).
- **Mutation.** The existing `BeforeRelationshipMutateEvent` already carries the
  relation, so the subscriber resolves `relation.securityMutate() ?? type.securityUpdate()`
  — the relation's gate when declared, else the parent's.

The subject is the **parent** resource in every case (the relationship hangs off it), so
expressions read like `is_granted('VIEW_BILLING', object)`. A declared value **replaces**
the parent's gate, letting a relation be more *or* less permissive; `string` is enforced,
`true`/`false` are documentation-only, `null` inherits — identical to the resource-level
keys. `RelationMetadata` surfaces the two declarations so the OpenAPI projection (core
ADR 0099) reflects the override. Dual-provider conformance (`securedWidgets` over both
the Doctrine and in-memory kernels) proves the seam is provider-agnostic.
