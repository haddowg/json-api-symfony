# The update gate checks both the stored and the changed resource

An update expression evaluated only after the body was applied reads the owner the request just wrote, so a caller could edit someone else's resource by naming themselves its owner. The update expression is therefore evaluated twice: on the stored resource as soon as the target loads (`BeforeApplyUpdateEvent`, before validation), and again on the changed resource, so a caller also cannot turn a resource into one the expression refuses them, such as handing it to another user. Relationship changes, at their endpoints, in an update body or inside an atomic batch, are applied inside the persister's transaction and checked after the change too (`BeforeRelationshipCommitEvent`, with the relation's mutate expression or else the update expression), and a refusal rolls them back.

## Consequences

Transferring ownership through an update needs an expression that also allows the new state. A caller refused on the stored state gets a `403` before validation, so it never sees `422`s for a resource it cannot edit. A custom persister without `TransactionalDataPersisterInterface` has already applied a relationship change when the second check refuses it.
