# A type's relations can be declared independently of a resource

Relations are a standalone capability: `#[AsJsonApiRelations(type: …)]` on a
`RelationsProviderInterface` class declares a type's relations with **no**
`AbstractResource`, held in a lazy, type-keyed `RelationsRegistry` (the registry is
type-keyed rather than class-string-keyed because relations are runtime objects, not
scalars core can read statically). `TypeMetadataResolver` sources relations
resource-first then from the registry, so a resource-less type (paired with
`#[AsJsonApiSerializer]`) gets working relationship endpoints and whole-resource
relationship writes that resolve a relation by name exactly as a resource would.

The route descriptor gains a `hasRelations` flag — true for any resource, and true
for a resource-less type that declared standalone relations — and the route loader
gates the relationship routes (related + relationship linkage GET/PATCH/POST/DELETE)
on it instead of on `isResource`, so a resource-less type with relations gets those
routes too. Rendering reuses core's `RendersRelationsTrait` (core ADR 0032): a
standalone serializer opts into the resolver via `SerializerResolverAwareInterface`
and builds its `getRelationships()` callables from the type's standalone relations.

`AbstractResource` stays the sugar that bundles a serializer, a hydrator, the field
DSL **and** the relations into one declaration; this is the decoupled path for a type
whose relations are declared on their own.
