# Relation data is gated by the relation's own permission, else by the related type's gates

A relation's `security(read:, mutate:)` replaces the parent's gate at its endpoints and the related type's gates for its data. Without one, a to-one target needs its own type's `securityRead` with the row as subject and reads as absent when refused, while a to-many is shown whole only when the caller passes the `securityList` of every type it relates to and is otherwise withheld to its links. Permission decides whether a caller sees a relation's data at all; which rows exist is scoping (Doctrine extensions, scoped providers), which always applies, so a list is never filtered row by row.

## Considered Options

- Filter a to-many's rows after the fetch: pages come back short and totals count rows the caller cannot see.
- Refuse the whole document when an included row is refused: one row fails a valid request and reveals that the row exists.

## Consequences

- A relation hidden from the caller is a `404` at its endpoints, checked straight after the parent loads and before any read gate, so a `401` or `403` never reveals it.
- Rendering a relationship's data and writing a flattened `on()` attribute onto a related row go through the same expressions, via the `RelationAuthorizer` core consults.
- A row a to-one refuses can still appear through a to-many whose query returns it, because the two follow different gates.
