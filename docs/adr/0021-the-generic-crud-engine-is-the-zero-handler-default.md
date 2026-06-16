# The generic CRUD engine is the zero-handler default

Phase 4's capstone is a *refactor*, not a build: the single
`CrudOperationHandler` was generic over types from the start (it dispatches on
operation type and resolves a per-type `DataProvider`/`DataPersister` and the
per-type serializer/hydrator), so an application gets the whole JSON:API endpoint
set for a type by declaring **nothing but** a resource (and, for the reference
Doctrine path, an `#[AsJsonApiResource(entity: …)]` mapping) — no per-type handler,
route, serializer or persister code. We make that guarantee explicit and
regression-guarded with a genericity witness (`tags`) that runs the full endpoint
set on **both** providers, added with only its resource + entity/POJO.

The engine's only structural concession to genericity was the repeated
`try { $server->resources()->resourceFor($type) } catch (NoResourceRegistered)`
dance — a type *may* have no `AbstractResource` (a bare serializer/hydrator pair
declares no field inventory). We collapse it into one resource-presence-aware seam,
`TypeMetadataResolver`, that returns `?AbstractResource` / `?RelationInterface` and
never throws, so the handler stays generic over both a full resource and a bare
pair without per-type branching. The seam is the single point the later
override slices plug into (the bare-pair path, ADR 0024).

Per-type customization composes **without** per-type handler code: a
higher-priority `DataProvider`/`DataPersister` (ADR 0007) overrides storage, a
custom serializer/hydrator overrides I/O (ADR 0023), and decorating the
`CrudOperationHandler` service overrides operation semantics (ADR 0025). We keep
one global handler rather than a per-type handler registry because those three
seams already cover the real customization surface, and core's `Server` holds
exactly one handler — a per-type dispatch layer would duplicate, in the bundle,
resolution the SPIs already do.
