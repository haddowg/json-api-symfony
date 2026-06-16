# Multi-server is config-declared; a resource joins one or more named servers, routes mount per server

The bundle realises the long-seamed multi-server capability. Top-level
`base_uri`/`version` define the implicit `default` server, so a single-API
application still needs no `servers:` block (unchanged). An optional
`json_api.servers` map declares additional named servers, each with its own
`base_uri`/`version` (inheriting the top-level value when omitted). A resource —
or a standalone serializer/hydrator/relations capability — joins a server with
`#[AsJsonApiResource(server: 'admin')]`; `server` accepts a single name **or a
list**, so the same type can be exposed on several servers at once, and an unset
`server` means `default`.

One immutable core `Server` is built per declared server (a `ServerFactory`
each), holding only that server's registered types; `ServerProvider` resolves a
server by the `_jsonapi_server` route default from a name→factory service
locator (an unknown name is a `LogicException`, a wiring fault). Routes mount per
server through a **per-server route import** (`resource: <name>`, `type:
jsonapi`), so prefix/host/condition stay in the application's routing config
where Symfony users expect them; the bare / `.` / `default` import keeps emitting
the `default` server with its existing unprefixed route names
(`jsonapi.{type}.{action}`), while a named server's routes are namespaced
`jsonapi.{server}.{type}.{action}` so a type exposed on two servers never
collides. The kernel listeners are unchanged — they already resolve the server
by name through `ServerProvider`.
