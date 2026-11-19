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

**Singleton type**:
A type with one resource per caller, declared through core's `$singleton` static (or a serializer implementing `SingletonResourceInterface`) and served at `/{uriType}` with no `{id}`. The bundle resolves the caller's id through `resolveSingletonId()` once per request and then runs the ordinary operation on it; the type's data layer must expose only the caller's row.

**Related-only type**:
A type declared `#[AsJsonApiResource(relatedOnly: true)]`: it serves no endpoint of its own and is reached only as another type's related or included resources, or as a custom action's `inputType`.
_Avoid_: embedded type, endpointless type

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
A data persister that also implements `TransactionalDataPersisterInterface`, so every single write through it runs in one write transaction and it can take part in an Atomic Operations batch.

**Write transaction**:
The transaction a single create, update, delete, relationship write, or action called with a writing method runs in, from its first write step and every before hook through the persister's write. A CRUD write's after hooks run once it commits; an action's `AfterActionEvent` runs inside it.

**After-commit callback**:
A callable registered on the `AfterCommit` service that runs once the current write transaction commits, before the after hooks; a rollback drops it, an Atomic Operations batch holds it until the batch commits, and outside any write it runs at once.
_Avoid_: post-commit hook (the deferred After* hooks of a batch)

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
The reason a Doctrine query is being run (a primary fetch, a related load, a write's target load, an include hydration, a collection action's filtered rows), passed to an extension so it can exempt one.

**Scope**:
What decides which rows of a type exist for a request: its Doctrine extensions or a scoped provider. A **scoped type** is one whose rows a scope can hide.
_Avoid_: filter, permission

**Pivot field**:
A value stored on the join of a `belongsToMany`, rendered and written at each linkage member's `meta.pivot`.

**Association entity**:
The Doctrine entity that models a `belongsToMany` join so it can carry pivot fields.
_Avoid_: join table (when it has columns)

**Foreign-key read**:
How a relation declared with a foreign key (core's term) is read: always by the related type's provider, through `ReadsByForeignKeyInterface`, as the rows whose foreign key holds one of a page of parents' keys, whatever provider serves the parents. The parent model never holds the relation, and a write of it sets or clears the foreign key on the related rows through the related type's persister (`WritesForeignKeysInterface`).
_Avoid_: inverse read, reverse lookup

**Windowed include**:
A to-many whose linkage renders, included or `withData()`, cut to its first page per parent under its effective paginator, and reordered or narrowed by a Relationship Queries `relatedQuery`.

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

**Typed document meta**:
The top-level `meta` members a type's `documentMeta()` declares for one operation's document, or an action handler's `documentMeta()` for one response status, which the CRUD handler fills from their `extractUsing()` hooks and, in debug mode, the view listener and the atomic loop check.
_Avoid_: response meta

**Typed request meta**:
The top-level `meta` members a type's `requestDocumentMeta()` declares for one write, or an action handler's for one HTTP method, which the handler validates as attributes before it hydrates and puts on the request as the written meta.
_Avoid_: request options

**Declared resource meta**:
The `meta` members a type's `resourceMeta()` declares for its resource objects, rendered from their `extractUsing()` hooks on a read and validated, then filled through `fillUsing()` or left on the written meta, on a write.
_Avoid_: resource meta (alone, it also names what `getMeta()` returns)

**Written meta**:
The typed values of a write's top-level `meta` and `data.meta`, carried on its request, which the hydrator, the lifecycle events, the hook context and an action's context read.

**Exception mapper**:
A tagged service that turns a non-JSON:API throwable into an error response on a JSON:API route.

### Actions, batches and async writes

**Custom action**:
A non-CRUD endpoint declared with `#[AsJsonApiAction]`, mounted under the reserved `-actions` segment at resource or collection scope.
_Avoid_: RPC endpoint, command

**Filtered collection**:
The rows of its mount type a collection-scope custom action declared with `filters` reads, selected by the request's `filter[…]` parameters after the type's collection read gate. Its handler gets it from `ActionContext::filtered()`.

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
