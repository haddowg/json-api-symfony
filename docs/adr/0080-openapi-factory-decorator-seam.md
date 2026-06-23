# Wholesale OpenAPI customisation runs through a priority-ordered decorator seam applied inside the DocumentFactory

Inline authoring (`->describedAs()`/`->example()`) and config (`info`/`servers`/
`security`/`tags`) cover the common customisation cases, but some mutations the
projection cannot express declaratively — a server variable, an extra security scheme,
per-individual-CRUD-operation tags, vendor extensions, hand-written examples, or
rewriting any part of the document. So the bundle ships an `OpenApiFactoryInterface`
decorator seam (`decorate(OpenApi $document, string $server): OpenApi`): an app service
implementing it is autoconfigured onto the `OPENAPI_FACTORY_TAG`, the `DocumentFactory`
consumes the tagged services (Symfony yields them highest priority first, so the factory
reverses the iterator) and **applies them in ascending priority** — lower priority first,
the **highest-priority decorator applied last gets the final word** (the bundle's
highest-wins convention, consistent with providers/persisters/mappers) — **after** the
core projection. The app's decorators get the last word over anything the projector
produced (design §5, D7).

The seam lives **in the bundle**, not core (core is the framework-agnostic projector;
wholesale post-processing is a Symfony-DI concern), and is applied **inside the
`DocumentFactory`** rather than at each call site, because every build path — the
`cache:warmup` warmer, the controller's dev lazy-build, and the CLI export — flows
through that one factory, so decorating there runs the chain uniformly for all three with
no duplication. Decorators receive the immutable `OpenApi` VO and return a (typically
`with*`-derived) mutated one, so the contract is purely functional and composition order
is the only coupling.
