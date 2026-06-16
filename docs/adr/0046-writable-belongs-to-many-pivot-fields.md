# Writable belongsToMany pivot fields over an association-entity diff

Building on the read-only pivot feature (ADR 0045) and core's upgrade of
`BelongsToMany::fields()` from a `name => type` map to real `FieldInterface`
definitions (core ADR 0053), pivot fields are now **writable by default** — set,
validated and reordered through the linkage's resource-identifier `meta`. Opt a
server-owned column out with `->readOnly()`. The bundle's read consumers
(`PivotFields`, the Doctrine provider's render/filter/sort) move to reading
`name()` / `column()` / `constraints()` / the field's own value cast off the
`FieldInterface` (no ad-hoc type map), and two write halves are added.

**Validation.** The Symfony Validator bridge validates each incoming linkage
member's `meta` against the relation's `writablePivotFields()` constraints — reusing
the same `Required`/`Nullable` resolution and `Collection` machinery as attributes —
rendering a violation as a `422` pointed at the linkage meta
(`/data/relationships/<rel>/data/<n>/meta/<field>` for a whole-resource write,
`/data/<n>/meta/<field>` on a relationship endpoint). Because an add/replace (a
relationship-endpoint `POST`/`PATCH`, or a whole-resource `POST`/`PATCH` whose
relationship apply runs in `Mode::Replace`) may **create a new association row** for
any incoming member — even on a `PATCH` — the `meta` validates in the **new-row
(create) context**, matching the persister, which writes a new row in create context
(`writablePivotFields(true)`); a reorder of an existing row supplies the value, so
this never wrongly rejects it. A **read-only** pivot field supplied in `meta` is
**ignored** (it is not in the writable set, and the Collection allows extra fields, so
it never raises and is never written — matching how a read-only attribute is handled).
A **required** writable pivot field absent when a new row is created is a `422` —
before persist, never a database NOT-NULL `500` — on every new-row path: the
relationship-endpoint `POST`/`PATCH` and the whole-resource `POST`/`PATCH` alike.

> *Follow-up correction.* The validation originally resolved the pivot context from
> the *operation's* create/update flag (and the relationship endpoint was hardcoded to
> update), so a required writable pivot field absent on a new row reached the persister
> as a `500` on the relationship-endpoint `POST` and the whole-resource `PATCH` — only
> the whole-resource `POST` held the contract. Pivot-meta validation now keys on the
> *row's* new-vs-existing context (the new-row create context for any add/replace),
> matching the persister's per-row create/update split.

**Persistence (Doctrine, the reorder engine).** The reference Doctrine persister
applies the linkage `meta` as an **association-entity diff** over ADR 0045's
auto-detected `PivotAssociation`: for each incoming `(member, meta)` it upserts the
join row — updating an existing row's writable pivot fields **in place** (the
reorder) or creating a new row (writable fields from `meta`, read-only fields taking
their server default) — and on `Mode::Replace` removes the rows whose member left the
set (full sync), on `Mode::Add` leaves the rest, on `Mode::Remove` removes the
incoming members' rows (no `meta`). A new row's create-vs-update writability is
resolved per row (a created row uses the create context, an updated row the update
context), and values are coerced through each field's own cast. The persister never
writes a read-only field from `meta`. The same diff runs for a pivot relationship
embedded in a whole-resource write. The persister reads the per-member `meta` off the
parsed `ToManyRelationship` directly (no SPI-signature change), gated on the injected
`PivotAssociationResolver` recognising a pivot relation, so a non-pivot to-many keeps
the plain collection-mutation path unchanged.

**Boundaries.** Doctrine-only (an association entity is required to query *and*
write) — the in-memory provider has no join row, so a pivot-meta write is **ignored**
there (consistent with read being unsupported), the relation behaving as a plain
to-many. This is the write twin of ADR 0045's read-only pivot, mirroring how core
co-evolved the read-only DSL into writable field definitions in core ADR 0053.
