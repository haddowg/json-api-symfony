# Moving a member out of another parent needs that parent's gate or the member's

On the inverse side of a Doctrine one-to-many, an add or replace that names a child belonging to another parent re-points the child, which also removes it from that parent, yet only the target parent's gate was checked. The persister now reports each child whose parent would change (`ReportsMovedMembersInterface`), the handler dispatches `BeforeMemberMoveEvent` for it on the relationship endpoints and for relationships set in a write body, and the security subscriber allows the move when the caller passes the relation's mutate expression (or the type's update expression) for the parent the child leaves, or the related type's update expression for the child. Either one is what the caller would need to make the same change directly, through the other parent's relationship or the child's own to-one, so this rule refuses only moves the caller couldn't otherwise make; a child with no parent needs the child's gate.

## Considered options

Requiring both gates would refuse moves the caller can already make through the child's own endpoint. A `409` would hide that the refusal depends on who's asking, so a refusal is a `403` like the other gates.

## Consequences

The in-memory persister doesn't implement the interface: its to-many lists aren't owned by a single parent, so adding a member never takes it from another list and there is nothing to authorize. A custom persister with single-parent children implements the interface to get the same check. A remove only detaches current members, on both providers.
