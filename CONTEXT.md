# JSON:API Symfony bundle

The Symfony integration of `haddowg/json-api`: dependency injection, routing and the request lifecycle around core, plus a reference Doctrine data layer. Core concepts (resource, serializer, hydrator, relation, server, operation, target, paginator, window, profile, error catalogue…) are defined in [haddowg/json-api's CONTEXT.md](https://github.com/haddowg/json-api/blob/main/CONTEXT.md); this glossary covers only the bundle's own terms.

## Language

### Capabilities and wiring

**Capability**:
One independently declared part of a type: its serializer, hydrator, relations, data provider or data persister. A type is any combination of capabilities.
_Avoid_: feature, component

**Standalone capability**:
A serializer, hydrator or relations set registered for a type with `#[AsJsonApiSerializer]`, `#[AsJsonApiHydrator]` or `#[AsJsonApiRelations]`, with no `AbstractResource`.

**Operation allow-list**:
The set of CRUD operations a type declares it serves; the route loader emits a route for each one and nothing else.
_Avoid_: exposed endpoints

**Named server**:
A server declared under `json_api.servers`, beside the implicit **default server** defined by the top-level `base_uri`/`version`.

**Mount**:
The path prefix a server's routes are imported under, recorded on each route so links built from an empty `base_uri` carry it.
_Avoid_: route prefix (when meaning the recorded value)

**JSON:API route**:
A route carrying the bundle's route marker; only these routes get JSON:API error rendering and the bundle's response listeners. `JsonApiRoute` is its public view.

### Data layer

**Data provider**:
The read side of the storage SPI: fetches one resource, a collection, a related collection, a batch of related collections or relation counts for a type.
_Avoid_: repository, data source

**Data persister**:
The write side of the storage SPI: instantiates, creates, updates and deletes resources and mutates relationships for a type.
_Avoid_: writer, saver

**Transactional persister**:
A data persister that also implements `TransactionalDataPersisterInterface`, so it can take part in an Atomic Operations batch.

**Reference data layer**:
The Doctrine ORM provider and persister the bundle ships as the default implementation for entity-mapped types.

**In-memory provider**:
The array-backed provider and persister used as the test double and the conformance witness for the reference data layer.
_Avoid_: fake provider

**Conformance suite**:
A functional test case run with identical assertions against both the in-memory and the Doctrine kernels.
_Avoid_: dual test

**Collection criteria**:
A collection request resolved against the declared vocabulary: its filters, sorts, window and count decision, as handed to a provider.

**Criteria applier**:
The shared step that matches requested filter and sort keys against the declared vocabulary, folds in filter defaults and applies them through a provider's handlers.

**Doctrine extension**:
A tagged `DoctrineExtensionInterface` service that adds a base constraint to every Doctrine query for a type before the requested criteria.
_Avoid_: query scope (for the service itself), Doctrine filter

**Query purpose**:
The reason a Doctrine query is being run (a primary fetch, a related load, a write's target load, an include hydration), passed to an extension so it can exempt one.

**Scope**:
What decides which rows of a type exist for a request: its Doctrine extensions or a scoped provider. A **scoped type** is one whose rows a scope can hide.
_Avoid_: filter, permission

**Pivot field**:
A value stored on the join of a `belongsToMany`, rendered and written at each linkage member's `meta.pivot`.

**Association entity**:
The Doctrine entity that models a `belongsToMany` join so it can carry pivot fields.
_Avoid_: join table (when it has columns)

**Windowed include**:
An included to-many rendered as one page per parent, under the Relationship Queries profile or a relation's own pagination.

### Lifecycle and authorization

**Lifecycle hook**:
A Symfony event the CRUD handler dispatches before or after an operation; a resource may implement the same hooks as methods.
_Avoid_: callback, listener (for the event itself)

**Security expression**:
A Symfony ExpressionLanguage string declared on a resource, relation or action and enforced on the lifecycle events.

**Documentation-only security**:
A `true` or `false` in place of a security expression: it marks the operation secured or public in the OpenAPI document and enforces nothing.

**Relation permission**:
A relation's own `security(read:)` declaration, deciding whether a caller sees that relation's data at all, independently of scope.

**Exception mapper**:
A tagged service that turns a non-JSON:API throwable into an error response on a JSON:API route.

### Actions, batches and async writes

**Custom action**:
A non-CRUD endpoint declared with `#[AsJsonApiAction]`, mounted under the reserved `-actions` segment at resource or collection scope.
_Avoid_: RPC endpoint, command

**Input mode**:
How a custom action reads its request: `None`, `Document` (a JSON:API document) or `Raw` (any declared media type).

**Atomic batch**:
One `POST /operations` request, run as a single transaction through the CRUD handler.

**Async accept**:
A persister's `AcceptedForProcessing` return, rendered as a `202` instead of the written resource.

### OpenAPI serving

**Artifact**:
A document or schema the cache warmer writes to the cache directory and the controllers serve.

**Expose gate**:
The condition (`kernel.debug` or `json_api.openapi.expose_in_prod`) under which the OpenAPI and JSON Schema routes exist.

**Build-time decorator**:
An `OpenApiFactoryInterface` service that rewrites the document while it is built.
_Avoid_: document decorator (ambiguous)

**Serve decorator**:
An `OpenApiServeDecoratorInterface` or `JsonSchemaServeDecoratorInterface` service that rewrites a served document per request.

**Servability warmer**:
A non-optional cache warmer that fails the build on a configuration the bundle could not serve.
