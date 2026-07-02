# Prune include paths to types not serializable on the projected server

`IncludePathResolver` derived a type's advertised `?include` paths by walking the
relation graph, gated only on per-relation includability, the depth cap and the
allow-list. It never checked whether the related type is actually **serializable on the
server being projected**. In a multi-server API a relation can point at a type
registered only on another server (the example's `playlists.owner` → `users`, where
`users` lives on the `admin` server): the relation renders links-only on the default
server, so `?include=owner` there hydrates nothing — yet the default-server OpenAPI
document (and the generated client) advertised the include token and emitted a dead
accessor.

The walk now also requires every type reachable through a path to have a serializer on
the projected server (`Server::hasSerializerFor()`); a relation whose related type(s)
are not serializable there contributes no path and is not descended. So the advertised
include set matches what the server can actually hydrate. A polymorphic relation must
have all its member types serializable to be advertised (an include reaching an
unrenderable member is a partial contract).
