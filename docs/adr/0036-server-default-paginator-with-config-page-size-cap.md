# The bundle installs a configurable server-default paginator with a config page-size cap

Every server the bundle builds now gets a **default paginator** — the tail of
core's `relation → related resource → server default` fallback chain — resolved per
server in this precedence order:

1. a `PaginatorInterface` service registered for **this** server, id
   `haddowg.json_api.default_paginator.<name>` (e.g. a `CursorPaginator` on one
   server only);
2. else a generic `PaginatorInterface` service registered for **all** servers, id
   `haddowg.json_api.default_paginator`;
3. else the built-in `PagePaginator` (`page[number]`/`page[size]`) whose
   client-controlled page size is clamped to the configurable
   `json_api.pagination.max_per_page` cap (default `100`);
4. else `null` — when the cap is set to `0` and no custom paginator is registered,
   no server default is installed, so a collection that resolves to it renders
   unpaginated.

The **by-server-then-generic** resolution lets an application register one default
strategy for all servers, override it on a single server, or both. A custom
paginator owns its own page-size ceiling, so the `max_per_page` cap applies only to
the built-in fallback (3).

The built-in fallback closes a **page-size denial-of-service vector**: a client
controls `page[size]`, so without a ceiling `?page[size]=1000000` forces the store
to fetch a million rows; the cap clamps the resolved size down to the maximum (the
clamp-don't-`400` stance core takes for every garbage `page[…]` value) so an
over-large request returns the capped count with a `200`. The cap concept, the
`withMaxPerPage()` wither and the disable-with-`0` semantics are owned by **core**
(core ADR 0045); the `withDefaultPaginator()` server seam is core's too. The
bundle's contribution is the config key and the two optional service ids, threaded
through `JsonApiBundle::loadExtension()` into each `ServerFactory`
(`ServerFactory::resolveDefaultPaginator()`).

Installing a built-in server default is a deliberate posture change: previously the
bundle shipped **no** default paginator, so a collection with no
per-resource/per-relation `pagination()` rendered the whole list unpaginated. We
chose the secure default — every collection that resolves to the server default is
now paginated and protected without configuration — over preserving the
unpaginated-by-default baseline, because an uncapped, unbounded collection is itself
the larger DoS surface. A resource (or relation) that declares its own `pagination()`
still overrides the server default entirely.

## Consequences

- A related to-many collection whose relation declares no paginator now paginates
  via the server default (the dual-provider conformance witnesses were updated from
  asserting an unpaginated baseline to asserting the capped-server-default fallback).
- `max_per_page: 0` disables the **built-in** paginator (no server default, so
  collections that resolve to it render unpaginated) — but a custom paginator
  registered under either service id still takes precedence, so `0` is "no built-in
  default", not "never paginate".
- A custom paginator is registered as an ordinary service; its id (`...default_paginator`
  or `...default_paginator.<name>`) is what the `ServerFactory` reads via
  `nullOnInvalid()`, so registering it is the entire wiring — no compiler pass or tag.
