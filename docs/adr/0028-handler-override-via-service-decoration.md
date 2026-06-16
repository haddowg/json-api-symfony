# Per-type/per-operation handler override is Symfony service decoration

The single generic `CrudOperationHandler` is wired into the `ServerFactory` by
service id (`service(CrudOperationHandler::class)`), so an application overrides
operation handling for a specific type or operation by **decorating that service**
(Symfony decoration, e.g. the `#[AsDecorator(CrudOperationHandler::class)]`
attribute): the decorator intercepts the targeted operation and delegates every
other operation to the inner generic engine, and the factory resolves the
decorated service transparently. We chose decoration over a per-type handler
registry because it needs no bundle indirection and no core change — custom
handling composes as an escape hatch on top of the zero-handler default engine,
with the inner engine still serving everything the decorator passes through.
