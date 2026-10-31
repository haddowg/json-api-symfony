# Moving a member out of another parent needs that parent's gate or the member's

On the inverse side of a Doctrine one-to-many, adding a child that belongs to another parent re-points it and so removes it from that parent, which the target parent's gate alone does not cover. The persister reports each child whose parent would change (`ReportsMovedMembersInterface`) and the handler dispatches `BeforeMemberMoveEvent`, which passes when the caller passes the relation's mutate (or the type's update) expression for the parent the child leaves, or the related type's update expression for the child: either is what the caller would need to make the same change directly.

## Considered Options

Requiring both gates would refuse moves the caller can already make through the child's own endpoint, and a `409` would hide that the refusal depends on who is asking, so a refusal is a `403`.

## Consequences

The in-memory persister does not implement the interface, since its to-many lists are not owned by a single parent; a custom persister with single-parent children implements it to get the same check.
