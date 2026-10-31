# A persister accepts a write for asynchronous processing by returning a marker

`create()` or `update()` may return `AcceptedForProcessing` instead of the entity, and the handler renders core's `202` `AcceptedResponse`; completion is a custom action returning a `303` `SeeOtherResponse`. The persister already owns the storage decision, so it owns the dispatch decision too, and the bundle adds no queue, SPI or registry; how the work is queued stays the application's choice.

## Consequences

An async accept inside an Atomic Operations batch is refused with a `422` and rolls the batch back, since it would escape the batch's commit. `delete()` returns `void`, so there is no async delete.
