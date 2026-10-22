# Pivot meta reads the server's output timezone from a per-operation holder

Core applies `Server::withOutputTimezone()` when a resource renders its attributes,
but the Doctrine provider casts each pivot column to its wire string while it reads the
association rows, before any render and with no Server in reach. It is one service
shared by every server. So the `CrudOperationHandler` writes the operation's zone into
a `RequestScopedOutputTimezone` holder at the start of each operation, and the provider
passes it to `PivotFields::cast()`, which zones the field with core's
`withDefaultOutputTimezone()`. The filter-value cast and the cursor tokens read the raw
column and stay unzoned.

## Considered options

- **Pass the zone through `PivotAwareProviderInterface`.** Adding a parameter breaks
  every implementation, and `fetchRelatedPivotMapBatch()` has no request or criteria
  to carry it in.
- **Return raw pivot values and cast them in `PivotMetaSerializer`.** The render path
  has the Server, but the pivot map is documented as wire values and feeds the
  validator's merge-before-validate read, so its meaning would change under existing
  providers.

## Consequences

- A custom pivot-aware provider formats its own values, like a hand-written serializer,
  and gets the zone only if it reads the holder.
- A named server's `output_timezone` follows the `base_uri` rule and the Laravel
  adapter: omitted or `null` inherits the top-level zone, and `''` turns conversion
  off for that server.
