# A type exposes exactly the operations it declares, and the loader emits one route per operation

A JSON:API type declares which of the five CRUD operations it serves —
`FetchCollection`, `FetchOne`, `Create`, `Update`, `Delete` — as an `Operation`
allow-list on `#[AsJsonApiResource(operations: …)]` or
`#[AsJsonApiSerializer(operations: …)]`. The `JsonApiRouteLoader` then emits
**exactly one route per declared operation**, so read-only, create-only and
serialize-only types all fall out of the same mechanism rather than each needing a
bespoke registration path. The defaults preserve today's behaviour: an
`AbstractResource` declares no list and gets all five operations, while a standalone
serializer declares no list and gets none (serialize-only, as before).

Per-type route metadata flows from the discovery-time compiler pass
(`ResourceLocatorPass`) to the route loader as **plain scalar arrays** — a
`type → {uriType, isResource, hasHydrator, operations}` descriptor map where
`operations` is a list of `Operation` case-value strings. This is deliberate:
Symfony cannot dump an arbitrary value object as a compiled service argument, so the
public DX type (the `Operation` enum) is collapsed to its string case values on the
tag and rebuilt as a plain list in the descriptor. The pass also **compile-time
validates** that any type exposing a write operation (`Create`/`Update`) has a
hydrator, failing the build with a message pointing at `#[AsJsonApiHydrator]` or
`AbstractResource` rather than letting an unhydratable write reach a handler.

Relationship routes remain all-or-nothing per resource (emitted only for a full
resource, never for a standalone serializer); gating individual relationship
endpoints by per-relation exposure is a later slice.
