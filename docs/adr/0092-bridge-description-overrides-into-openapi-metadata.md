# Bridge resource / operation / relation description overrides into the OpenAPI metadata

Core now generates a default description for every documentable OpenAPI element and
consumes an author override (`TypeMetadataInterface::description()` /
`operationDescription()`, `RelationMetadataInterface::description()`,
`AbstractResource::getDescription()` / `describeOperation()`). The bundle bridges the
two declaration-site surfaces — the `#[AsJsonApiResource(description:, operationDescriptions:)]`
attribute and the resource method hooks — into the metadata the projector reads, with
precedence **method hook → attribute → null** (null lets core emit the generated
default). The method hook wins because it is the more specific, runtime surface; an
unknown `operationDescriptions` key is a compile-time error.

The attribute values flow through a dedicated type-keyed `ResourceDescriptionRegistry`,
assembled by a `ResourceDescriptionPass` from the `RESOURCE_TAG` tag attributes
(the resource-object description as a scalar, the per-operation map JSON-encoded into
one scalar — a nested map is not a dumpable flat tag attribute), exactly mirroring the
`ResourceSecurityRegistry` / `ResponseHeadersRegistry` precedent rather than inventing a
parallel mechanism. `operationDescriptions` keys by the `Operation` **case name**
(not the enum case, since a PHP array key cannot be an enum). Relationship descriptions
need no new plumbing: `RelationMetadata::description()` already delegates to the relation
field's `getDescription()`, so a `->describedAs()` on a relation reaches the projector's
related/relationship operation descriptions unchanged.
