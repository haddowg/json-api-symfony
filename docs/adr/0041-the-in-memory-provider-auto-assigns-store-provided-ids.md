# The in-memory reference provider auto-assigns store-provided ids; conformance fixtures source ids from the store

With *store-provided* now the default id source (ADR 0039), a plain `Id::make()`
create sets no id and relies on the storage layer to assign one. The reference
Doctrine provider gets this for free from `#[ORM\GeneratedValue]`, but the
**in-memory** reference provider keyed each item by the id read off the entity and
never assigned one — so a store-provided create had no id to key on. That is why the
shared conformance fixtures (run on *both* providers) were initially given a
`generateUsing(Id::generateUuid())` band-aid: it was the one source that worked on
both.

We made the in-memory provider **mirror DB auto-increment** instead: `InMemoryStore`
takes an optional `assignId` closure plus a monotonic sequence (seeded above the
highest existing id), and `save()` mints the next id onto an id-less item before
keying it. It is **opt-in** — a read-only store passes no `assignId` and still throws
on an id-less write, so existing seed-only fixtures are unaffected. Store-provided is
thus a genuine **dual-provider** behaviour, not a Doctrine-only one.

With that in place, the shared conformance fixtures were converted off the band-aid to
real store-provided ids: their Doctrine entities became `#[ORM\GeneratedValue]` int
PKs and their resources plain `Id::make()`; their previously non-numeric ids
(`a1`/`c1`/…) became **per-type sequential ints** matching insertion order, and the
in-memory fixtures were seeded to the same values. A Doctrine `AUTO`/`IDENTITY` column
ignores a pre-set id *by default* (the DB assigns it post-insert) — explicit seed ids
*are* achievable by overriding the entity's id generator to assigned
(`GENERATOR_TYPE_NONE` + an `AssignedGenerator`) in the (Foundry) instantiator, but we
did not need that: letting seeded rows take the auto-increment value is deterministic
on the per-test-recreated sqlite schema, and the fixture ids only need to be *stable*,
not *specific*. `generateUsing` now survives only on its dedicated witness
(`SlugResource`).

## Consequences

- A store-provided create round-trips identically on both providers (the new id comes
  back in the `201` body + `Location`); create assertions check the predictable
  next id (e.g. the 6th `article` after 5 seeded) rather than a generated UUID.
- The in-memory provider now models an auto-increment store, closing a reference-impl
  gap: store-provided was previously unexercisable there.
