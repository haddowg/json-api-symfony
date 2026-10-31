# Written linkage resolves through the related endpoint's scope

Every write path (create, update, relationship endpoints, Atomic Operations) loads each linkage identifier of a scoped type through that type's provider `fetchOne()` before the persister runs, as `GET /{type}/{id}` would, and rejects one it does not return with `404 RESOURCE_NOT_FOUND`, so a hidden id and a missing id are indistinguishable. A to-many replace on an existing parent changes only the members the client can see: the handler appends the current members the scope hides to the linkage it hands the persister, so their rows and pivot rows stay. Unscoped types keep accepting ids unchecked.

## Considered Options

- Let a replace drop hidden members: the client never saw them, and it would delete rows the scope exists to protect, such as another tenant's links.
- Reject a replace while hidden members exist: it reveals they exist and leaves the client no way to write the relationship.
- Check visibility inside each persister: every custom persister would have to repeat it, while the handler sees every write path first.

## Consequences

A persister's `mutateRelationship()` may receive replace linkage holding members the request did not name, and applies it as given. A relationship-endpoint mutation of a polymorphic to-many reaching a scoped type is refused with a `500` before it writes, because its response cannot be scoped.
