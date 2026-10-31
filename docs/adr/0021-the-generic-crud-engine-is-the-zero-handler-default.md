# One generic CRUD handler is the zero-handler default, and customisation composes around it

An application gets the whole endpoint set for a type by declaring only a resource (plus an entity mapping for Doctrine): a single `CrudOperationHandler` dispatches every operation and resolves the per-type provider, persister, serializer and hydrator. There is no per-type handler registry, because core's `Server` holds exactly one handler and the existing seams already cover customisation: a higher-priority provider or persister overrides storage, a custom serializer or hydrator overrides the wire shape, lifecycle hooks cover the flow, and decorating the handler service with Symfony decoration overrides an operation for a type while delegating the rest to the inner engine.

## Consequences

A decorator sees an Atomic Operations batch as one operation, not each sub-operation (ADR 0087).
