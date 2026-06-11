# A serializer and a hydrator can be registered for a type without a resource

A type's serializer and hydrator are independent capabilities, each registerable on
its own via `#[AsJsonApiSerializer(type: …)]` / `#[AsJsonApiHydrator(type: …)]` with
**no** `AbstractResource`. `AbstractResource` stays the preferred sugar — it bundles
a serializer, a hydrator, the relations and the field DSL from one declaration — but
the decoupled, capability-by-capability path exists for a type whose wire shape is
hand-written, or that has no resource at all.

The standalone services are ordinary **services**, so they may have constructor
dependencies: they resolve through the **same** `ResourceLocator` / `withContainer`
resolver core uses for resources (ADR 0023), and are registered against core via the
same `registerSerializerHydrator()` call a resource override would use. The
`ResourceLocatorPass` collects the tagged services into that locator and hands
`ServerFactory` `type → class` maps, which a `registerSerializerHydrator()` loop
feeds to core after the resources are registered.

A standalone **serializer is serialize-only**: it has no endpoints of its own, but it
feeds linkage and `?include` as a related/embedded type — the classic case a resource
*cannot* express, because registering a resource forces the full JSON:API endpoint
set for the type. The witness is a resource-less `colors` type that renders as the
`color` linkage on, and the `?include`d resource of, a `widgets` resource.

The capability is storage-orthogonal — a serializer transforms a domain object to the
wire regardless of where the object came from — so the witness runs on the in-memory
kernel only (the `colors` objects arrive through the parent widget, with no provider
or persister of their own).
