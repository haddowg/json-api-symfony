# Extensible Doctrine filter and sort handlers

`DoctrineFilterHandler` and `DoctrineSortHandler` now accept a constructor list of
author **arms** — `DoctrineFilterArmInterface` (a `supports()` test plus an
`apply(FilterInterface, QueryBuilder, value, alias)`) and `DoctrineSortArmInterface`
(`supports()` plus `apply(SortInterface, QueryBuilder, descending, alias)`) —
consulted when no built-in recognises the value object, before
`UnsupportedFilter`/`UnsupportedSort` is raised. So a consumer's own `FilterInterface`
/ `SortInterface` pushes down to DQL through a small focused service instead of
forcing a wholesale replacement of the reference provider. This consumes core's
extensible-handler seam (core ADR 0078) and is its data-layer half.

Arms are **autoconfigured** by their interface onto `DOCTRINE_FILTER_ARM_TAG` /
`DOCTRINE_SORT_ARM_TAG` and wired into `DoctrineDataProvider` as a `tagged_iterator`,
mirroring `DoctrineExtensionInterface` exactly — an app implements the interface,
registers the service, and the handler picks it up with no manual tagging. The
handlers stay `final` (composition over inheritance) and the arm list defaults to
empty, so the change is backward-compatible.

The two providers carry the seam **asymmetrically, by design**: the Doctrine arm is a
DI-autoconfigured extension point because `DoctrineDataProvider` is a container
service, whereas the in-memory provider is hand-constructed (a conformance witness,
seeded with literal objects a service definition cannot express), so its arms are
passed to the `InMemoryDataProvider` constructor directly. This mirrors the existing
reality rather than forcing the witness into the container.

One boundary is deliberate and documented: a custom **sort** arm orders the primary
collection and a non-windowed related collection, but is **not** consulted on the
natively-windowed/paginated related-to-many path (`WindowedRelationBatch`, which can
only express a `SortByField` window) — that raises a `LogicException` directing the
author to the bounded fallback or a custom provider. A custom **filter** arm has no
such limit; it composes on every path (it runs through the shared applier on the
related alias).

A custom filter/sort splits cleanly on portability. A **portable** one (e.g. order /
filter by a relation's count) ships both arms — the in-memory arm the conformance
witness, the Doctrine arm the `SIZE(...)` push-down — and selects/orders identically
on both providers under one conformance suite (the `RelationCountAtLeast` filter and
`OrderByRelationCount` sort demonstrators prove this end-to-end). An inherently
storage-specific filter (a raw-DQL scope) ships only the Doctrine arm and is simply
not declared on an in-memory resource. This closes the standing gap where a custom
`FilterInterface`/`SortInterface` had no Doctrine execution path short of a full
custom provider — the documented "declare your own handler" route now exists.
