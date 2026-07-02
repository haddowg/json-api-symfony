# A custom action declares a meta-only output; the handler's return type is guarded against its flags

A custom action could only advertise a resource-document `200` (its `outputType`,
defaulting to the mount type) or, with `returns204`, a `204`. A handler returning a
meta-only document (`$context->meta([...])`) had no way to say so, so the generated
OpenAPI document advertised a resource body it never returns. Several shipped
handlers (and both example actions) had already drifted this way.

`#[AsJsonApiAction]` gains `outputMeta: bool` — the twin of `returns204` — carried
through a new `ActionOutput` descriptor enum and mapped to core's `ActionOutputMode`
(core ADR 0102) so the projector advertises the shared meta-document `200`. Both
flags suppress the `outputType` default (its empty-string sentinel) and are mutually
exclusive with each other and with an explicit `outputType`. To stop the declaration
drifting from the handler, `ResourceLocatorPass` now **guards at compile time**: a
handler whose `handle()` return type is narrowed to exactly `NoContentResponse` must
declare `returns204`, and one narrowed to exactly `MetaResponse` must declare
`outputMeta` — a handler keeping the interface's union return type is unconstrained.
This is a pre-1.0 breaking change to the attribute and the descriptor.
