# Resource ids are sourced by the Id field policy; the bridge validates id format both ways

Core replaced the old hardcoded "stamp a v4 UUID when the client supplies no id"
behaviour with an explicit, format-aware model on the `Id` field: two orthogonal
axes — **client-id acceptance** (default forbidden → `allowClientId()` /
`requireClientId()`) and a **server-side fallback** when the client supplies none
(default *store-provided*: core sets nothing and the store/DB assigns the id →
`generated()` mints from the declared `uuid()`/`ulid()` format, or `generateUsing()`
takes a closure). The protected `AbstractResource::acceptsClientGeneratedId()` and the
auto-UUID `generateId()` fallback are **gone** (core ADR for the model). This ADR
records the bundle's consumption of that model.

The default flip is **behaviour-breaking**: a plain `Id::make()` create now persists
with no id set, so every non-`GeneratedValue` string-id fixture/resource that relied
on the auto-UUID would persist a blank id and break. We migrated each — a resource
whose entity has no DB sequence and must keep minting an id declares
`generateUsing(static fn() => Id::generateUuid())` (a UUID without pinning a route/
format constraint, so existing non-UUID seed ids still match), and the application-
assigned encoded-key witness (`cogs`) moved from `acceptsClientGeneratedId()` to
`requireClientId()`. The reference Doctrine persister already tolerated a store-
assigned id (it `persist()`/`flush()`es and reads the id back via `getId()` for the
`201` body + `Location`), so a `#[ORM\GeneratedValue]` entity needs no persister
change — proven by a new store-provided incrementing-int witness alongside witnesses
for each axis (`allowClientId` with/without an id, `requireClientId` → `403` when
absent, `generated()` uuid + ulid, `generateUsing`). The `generateUsing` migration
above was a **transitional** step for the shared conformance fixtures
(`articles`/`authors`/`comments`/`tags`/`books`/`vaults`): they were subsequently
converted to genuine store-provided incrementing-int ids — so `generateUsing` now
survives only on its dedicated witness (`SlugResource`). See ADR 0041.

The id **format helper** (`uuid()`/`ulid()`/`numeric()`/`pattern()`) declares
constraints core never executes; the bundle's **Symfony Validator bridge** executes
them in **both directions** before any decode: a client-supplied `data.id` is checked
against the *owning* resource's id format (`422` at `/data/id`), and each relationship
**linkage** id is checked against the *related* type's id format (`422` at
`/data/relationships/<rel>/data[/<n>]/id`) — for a polymorphic relation the format is
resolved from the linkage's own `type` member. The bridge resolves a type → its
resource → `Id` field → `constraints()` through the existing `IdEncoderResolver`
(extended with `formatConstraintsFor()`), reusing the same `ConstraintTranslator`
that gives the rest of the vocabulary teeth — so format validation needed no new
executor, only the type-resolution seam already built for encoded ids (ADR 0038).
