# A resource can override its serializer and hydrator

For a type whose wire shape the field DSL cannot express, a resource declares a
custom serializer and/or hydrator via `#[AsJsonApiResource(serializer: …, hydrator:
…)]`. The generic CRUD engine then drives that type's reads through the override
serializer and its writes through the override hydrator, instead of the resource's
field inventory — closing the "custom serializers/hydrators compose as escape
hatches" promise without any per-type handler code. Core already supported this
through `Server::register($resource, $serializer, $hydrator)`; the bundle only
bridges the attribute declaration to that call.

The overrides are ordinary **services**, so they may have constructor
dependencies. Core resolves a registered serializer/hydrator class-string through
the **same** `withContainer` resolver it uses for resources — the bundle's
`ResourceLocator`. So `ResourceLocatorPass` adds each override service to that
locator (keyed by its class-string) and hands `ServerFactory` a
`resourceClass → override` map (keyed by class-string, which the factory has in
hand — sidestepping the `type:`-override ambiguity), and `ResourceLocator::get()`
relaxes from "must be an `AbstractResource`" to "must be a `SerializerInterface` or
`HydratorInterface`" (an `AbstractResource` is both). The pass validates at
compile time that each declared override is a registered service implementing the
right contract.

Serializer/hydrator overrides are storage-orthogonal — they transform a domain
object to/from the wire regardless of the data provider — so the witness (a
`gadget` whose serializer upper-cases an attribute and whose hydrator prefixes it,
each with a bound constructor dependency to prove resolution-with-DI) runs on the
in-memory kernel only.
