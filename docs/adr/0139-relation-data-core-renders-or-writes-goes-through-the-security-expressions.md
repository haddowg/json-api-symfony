# Relation data core renders or writes goes through the security expressions

Core 1.9 asks the host before it renders a relationship's data and before a flattened `on()` attribute writes onto a related row (core ADR 0170), so each server is built with a `RelationAuthorizer` that answers from the expressions the security subscriber already evaluates at the relation's endpoints. A relationship renders its data only when the caller passes the relation's `security(read:)` expression with the parent as subject; a `null` read inherits the parent's read gate, which the caller passed to see the parent, and `true`/`false` only document the endpoints, so all three render. A flattened write needs the related type's update expression (`securityUpdate`, falling back to `security`) with the row as subject, and passes when the type declares none. This matches the Laravel adapter's ADR 0044.

## Consequences

The handler applies the body's relationships before core hydrates the flattened attributes, so the row checked is the one the same body just linked. `PivotSubstitutingResolver` passes the authorizer through, or pivot linkage rebuilt through it would skip the check. The shared service is bound to each server by its factory, which it needs only to tell which declared type a polymorphic relation's row is.
