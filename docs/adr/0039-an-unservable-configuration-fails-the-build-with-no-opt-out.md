# An unservable configuration fails the build, with no opt-out

Non-optional cache warmers check what would otherwise break at request time: a type with no provider, persister or `Id`, an `on()` chain through a to-many or unknown relation, a polymorphic candidate that does not override `getType()`, sort and filter columns Doctrine cannot resolve, pivot association discovery, declared links and action links, and relation security expressions nothing can evaluate. An unservable configuration is always a bug, so it surfaces at `cache:warmup` or deploy instead of as a `500` or a silent mis-render.

## Consequences

`getType()` is never derived from the entity map, because one entity may back several types.
