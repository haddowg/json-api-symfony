# Custom actions are a standalone capability under a reserved `-actions` segment, dispatched as a core operation

An `#[AsJsonApiAction]` handler mounts at `POST /{uriType}/{id}/-actions/{action}` or `POST /{uriType}/-actions/{action}`; a JSON:API member name cannot start with a dash, so the segment never collides with an id or a relationship name. An action dispatches as core's `CustomActionOperation` through `Server::dispatch()` and the one CRUD handler, inheriting strict query-parameter validation, the serving gate and handler decoration, and its request and response documents may use any registered type rather than the mount type.

## Consequences

- Input is `None`, `Document` or `Raw`. A `Raw` action negotiates its body against its declared `accepts` media types, and declared raw responses are negotiated against `Accept` before the handler runs, so `406` and `Vary` behave the same for every action; errors stay JSON:API documents.
- A handler whose return type is narrowed to `NoContentResponse` or `MetaResponse` must declare that output, checked at compile time, so the OpenAPI document cannot drift from the handler.
- Per-action `security` rides `BeforeActionEvent`. An action published as a resource link renders only for a caller who passes that same expression, and its href is server-relative so it takes the server's link base like every other link.
