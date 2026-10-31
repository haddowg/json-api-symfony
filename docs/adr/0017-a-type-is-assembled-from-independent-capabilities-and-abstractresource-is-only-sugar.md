# A type is assembled from independent capabilities, and `AbstractResource` is only sugar

Serializer, hydrator, relations, provider and persister are each declarable on their own (`#[AsJsonApiSerializer]`, `#[AsJsonApiHydrator]`, `#[AsJsonApiRelations]`, or the `serializer:`/`hydrator:` overrides on `#[AsJsonApiResource]`), and a type declares which CRUD operations it serves, the route loader emitting one route per declared operation. Registering a resource forces the whole endpoint set, which cannot express a serialize-only type (one that only appears as linkage and in `included`), a read-only type or a hand-written wire shape, so nothing is coupled to `AbstractResource`.

## Consequences

- An `AbstractResource` serves all five operations by default; a standalone serializer serves none.
- The build fails when a type exposes a write without a hydrator.
- Standalone capabilities resolve through the same container-backed locator as resources, so they may have constructor dependencies.
