# Another type's rows are shown under that type's own read gates

A type gated only by its read expressions could still be read through another type, as an `included` resource, as linkage or at a related endpoint, because only the parent's read was checked. A single related row (a to-one target) now needs its own type's read expression (`securityRead`, falling back to `security`) with the row as subject, the check `GET /{type}/{id}` makes, and a refused row reads as absent: a to-one renders `null`, adds nothing to `included`, its related and relationship endpoints answer `data: null`, and an `on()` attribute through it reads `null` and refuses a write with `422` as a missing row does. A to-many is a list, and a list shows what its query returns, so it is never filtered row by row: it is shown whole when the caller passes the collection read expression (`securityList`, falling back to `security`, with no subject) of every type it relates to, the check `GET /{type}` makes, and otherwise withheld whole, rendering only its links, and its related and relationship endpoints refuse the caller as `GET /{type}` would. This matches the Laravel adapter's ADR 0045.

## Considered options

- Filter a to-many's rows after the fetch. Pages would come back shorter than their size and totals would count rows the caller can't see; which rows a list holds belongs to the query, through Doctrine extensions and scoped providers, as for `GET /{type}`.
- Refuse the whole document with a `403` when an included to-one is refused. One row would fail an otherwise valid request and show that the row exists.

## Consequences

The to-one check runs in the linkage seam the handler already gives core, on targets already loaded, once per row and type per operation, and only for types that declare a read expression. The collection check runs once per type per operation, and a polymorphic to-many is withheld when any of its types refuses the caller. A row a to-one refuses can still appear through a to-many whose query returns it, since the two follow different gates; rows that should never be listed belong in a Doctrine extension or a scoped provider. Apps that relied on `?include` or a related endpoint to expose rows these gates refuse will see them hidden.
