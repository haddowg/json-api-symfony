# Written linkage resolves through the related endpoint's scope

ADR 0125 scoped what a document reads, but the persisters resolved the linkage a write
sends straight to rows with `getReference()` or a raw store lookup, so a create, an update,
a relationship endpoint or an atomic operation could attach, replace with or remove a
resource the related endpoint hides. Before the persister runs, the handler now loads
every identifier whose type is scoped through that type's provider `fetchOne()`, as
`GET /{type}/{id}` would load it, and rejects one it does not return
with the `404 RESOURCE_NOT_FOUND` JSON:API prescribes for a reference to a related
resource that does not exist. A hidden id and a missing one therefore produce the same
document, and a pivot member the scope hides validates as a new member, as a missing one
would. "Scoped" is the read side's decision: `ScopesRelatedReadsInterface`, a Doctrine
extension, or a provider that does not say. Unscoped types keep the old behaviour,
including accepting an id that does not exist.

A to-many replace on an existing parent changes only the members the client can see: the
handler appends the current members the scope hides to the linkage it hands the persister,
so their rows and pivot rows stay. A to-one has a single slot, so a client allowed to
write it replaces whatever it holds.

## Considered options

- **Let a replace drop hidden members.** The client asked for exactly the members it
  sent, but it never saw the hidden ones, and a replace would delete rows the scope
  exists to protect, such as another tenant's links.
- **Reject a replace while hidden members exist.** It reveals that the relationship
  holds members the client cannot see, and leaves the client no way to write it.
- **Check visibility inside each persister.** Every custom persister would have to
  repeat it; the handler sees every write path before any persister runs.

## Consequences

- An identifier of a scoped type costs one `fetchOne()`, and a replace one more per
  current member it did not name; unscoped relations cost nothing.
- A relationship endpoint renders the scoped linkage after a mutation, so a response
  never echoes a hidden member the parent still holds.
- A relationship endpoint mutation of a polymorphic to-many reaching a scoped type is
  refused with a `500` before it writes: its response, like its read (ADR 0125), cannot
  be scoped. A resource body is checked the same way, but rendering the resource
  afterwards is still refused whenever that relation's linkage data renders.
- A persister's `mutateRelationship()` may receive a replace linkage holding members the
  request did not name; it applies the linkage as given.
