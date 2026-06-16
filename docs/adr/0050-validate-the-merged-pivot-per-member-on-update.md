# An update validates the merged pivot per relationship member

The pivot half of merge-before-validate (the attribute half is ADR 0049). A
`belongsToMany` pivot relation carries per-member `meta` (`position`, a server-owned
`addedAt`, …); writing it is an association-entity diff that reorders an existing row
in place or creates a new one. Before this decision the validator bridge ran the pivot
`meta` constraints in a single **always-create** context — a band-aid the
pivot-writes work introduced so a required-on-create pivot field absent on a freshly
created row 422s before the DB NOT-NULL 500. But that band-aid also 422'd a required
pivot field omitted on the partial update of an **existing** member, even though the
persister preserves the stored value in place — a false rejection of a legitimate
reorder, and the pivot mirror of the attribute partial-update problem.

**Decision (HALF B — pivot).** Validate each relationship member's pivot `meta` in the
per-member new/existing context. A member whose related id is already in the
relationship merges its **stored pivot row** under the incoming meta
(`array_merge(stored, incoming)`) and validates in the **update** context (a writable
field absent from meta keeps its stored value, so a required-on-create rule does not
spuriously fail); a genuinely-new member validates the incoming meta in the **create**
(new-row) context (a required writable pivot field absent on it is still a `422`, never
the DB NOT-NULL `500`). The same per-member branch runs on both write surfaces: the
whole-resource linkage path and the relationship-mutation endpoint (`Mode::Add` and
`Mode::Replace`; `Mode::Remove` carries no pivot, untouched). A cross-pivot-field rule
(`CompareField` between two pivot fields) is now evaluated over the merged meta too, so
a comparison against a sibling pivot field the partial did not re-send sees the merged
state. This **supersedes** the always-create-context band-aid documented on
`validateRelationshipLinkage()`/`pivotMetaErrors()`.

The existing pivot rows reach the validator through a new **read-side seam** on the
`DataProvider` SPI — `fetchRelationshipPivot(string $type, object $parent,
RelationInterface $relation): array` returning `relatedId => [pivotField => wire
value]`. The handler reads it off the already-loaded parent (no re-fetch) for each
pivot relation in a whole-resource write, and for the one relation on a
relationship-endpoint mutation, and threads it into `validate()` / `validateRelationshipLinkage()`.
The reference Doctrine provider reuses the association-entity projection the pivot-read
feature already built (`fetchRelatedPivotMap()`) — no duplicated query. This is
**bundle-only**; there is no core change.

**Boundary.** Pivot data is Doctrine-only (a pivot column needs an association entity
the in-memory provider cannot model). The in-memory provider — and any standalone/custom
provider with no pivot storage — returns `[]`, so every incoming member is treated as
new (create context), exactly the documented in-memory pivot boundary
(`InMemoryPivotBoundaryTest`, `InMemoryPivotWriteIgnoreTest`). The per-member merge
context is therefore witnessed end to end on the Doctrine kernel
(`DoctrinePivotWriteTest`), where pivot rows actually exist.
