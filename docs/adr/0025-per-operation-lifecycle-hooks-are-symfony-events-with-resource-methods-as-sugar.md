# Per-operation lifecycle hooks are Symfony events, with resource methods as sugar

The handler dispatches fixed before and after events per operation (create, update, delete, relationship mutation, reads). A before event receives the entity mutable and aborts by throwing a JSON:API exception; an after event fires post-commit and may replace the response. A resource can implement `ResourceLifecycleHooksInterface` instead of registering a subscriber, and the hooks, events and context live in the bundle rather than on core's `AbstractResource`, because core has no CRUD lifecycle of its own and must not depend on the bundle's event types. This replaced decorating the whole handler as the seam for authorization, guards and imperative validation.

## Consequences

The hooks are inert without `symfony/event-dispatcher`. Inside an Atomic Operations batch, after events wait for the commit (ADR 0038).
