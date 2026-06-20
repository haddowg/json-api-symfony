# Counting & pagination totals — consistent, opt-in design (G21+, for approval)

**Status:** design only — supersedes the earlier `withoutCount()` sketch. Needs sign-off
on the open decisions (§7). Spans core (paginator flag, `_self_` token, profile rename)
+ bundle (handler/providers honour it). **Breaking** (flips today's count-by-default).

## 1. Principle — predictable, opt-in counting

One rule the whole surface obeys: **a paginator never counts unless told to, and the
total — wherever it appears — is computed once and rendered consistently.** Defining a
paginator at *any* level (server default / resource / relation) means *windowed, no
total* by default. The expensive `COUNT` only happens when an author or a client asks
for it, or when there is no window to count against (so it's free).

`meta.total` is the **universal cardinality slot** — top-level on a primary collection,
`relationships.{rel}.meta.total` on an inline relationship. `meta.page.total` is the
**pagination echo** of that same number, present only when a paginator is in play. They
are never computed twice.

## 2. The levers

| Lever | Level | Default | Effect |
| --- | --- | --- | --- |
| **`withCount()`** | paginator (non-cursor) | off (count-free) | this paginator runs the `COUNT` on **every** paged request — author opt-in, no profile/param needed |
| **`countable()`** | resource **and** relation | off | enables the **client** per-request count via `?withCount` (under the profile). Does nothing on its own; but when requested it counts **even if the paginator's default is not to** |
| **`?withCount=…`** | request param | — | client opt-in: names `_self_` (the primary collection) and/or relation names; valid only for `countable()` targets under the negotiated profile |

- `withCount()` is **author-always**; `countable()` + `?withCount` is **client-on-demand**.
- The cursor paginator is inherently count-free and takes neither — it never counts.
- `countable()` is added to **resources** too (today it's relation-only), so a primary
  collection can be made client-countable.

## 3. Where the count lands (one count, two slots)

When a total is computed — for *any* reason — the handler writes it to **both** slots
that apply, from a **single** count (never double-counts):

- **`meta.total`** — always (top-level for a primary collection / related endpoint;
  `relationships.{rel}.meta.total` for an inline relationship).
- **`meta.page.total`** — additionally, when the collection has a paginator.

So `meta.total` is the consistent cardinality everywhere; `meta.page.total` is its
pagination-context duplicate.

### 3a. Count-free page meta — derive `to` from the item count

A count-free page already drops the count-dependent `meta.page` keys — `total`,
`lastPage`, and the `last` link (all derived from the total). **One addition (approved
2026-06-18):** the count-free page must still emit **`meta.page.to`**, derived from the
**rendered item count** rather than the total: `to = from + count(items) - 1` (and omit
`to` for an empty page, where there is no upper bound). The window's upper bound is
knowable without a `COUNT`, so a count-free page should expose it for symmetry with the
count-based page. (This applies to the page-number / offset / fixed strategies'
`paginateWithoutCount()`; the cursor page already derives `from`/`to` from its boundary
reader. `total` / `lastPage` / `last` stay count-only.)

## 4. `_self_` and the profile rename

`?withCount` today only names relationships. To let a client request the **primary**
collection's total, add the reserved token **`_self_`**: `?withCount=_self_` counts the
current request's primary data (gated on that resource/relation being `countable()`).
Relation names keep their meaning (`?withCount=comments` → that relationship's
`meta.total`); the two compose: `?withCount=_self_,comments`.

Because `?withCount` is no longer relationship-only, **rename the profile** from
*Relationship Counts* to **Countable** (keyword `countable`); the `?withCount` query
param name stays.

## 5. The no-paginator convenience

If the resolved paginator for a collection is **none** (no server default, none on the
resource/relation), the collection is **fetched whole** — so its size is already in
hand and counting is free. In that case render `meta.total` **unconditionally** (even
unrequested), for both a primary collection and a materialised to-many relationship.
There is no `meta.page.total` (no pagination). This keeps "no pagination" honest and
consistent: you always know the size, because you fetched all of it.

*(A relationship that is lazy — links-only, not fetched — has nothing materialised to
count for free, so it stays count-free until `?withCount`/`withCount()`/include forces
the data.)*

**How a collection ends up with no paginator (resolved 2026-06-18).** Today
`AbstractResource::pagination(): ?PaginatorInterface` returns `null` by default and the
*resolver* coalesces `null → server default`, so a resource can never actually be
unpaginated. We invert that: **`pagination()` is the single source of truth — its return
value is used verbatim (a `null` return means *no pagination*, fetch-all).** To let the
default still inherit the server default, the method **receives the server default as an
argument** and the base implementation returns it:

```php
// AbstractResource
public function pagination(?PaginatorInterface $serverDefault): ?PaginatorInterface
{
    return $serverDefault;            // default: inherit the server default
}

// override to pin a strategy:        return PagePaginator::make()->withDefaultPerPage(20);
// override to disable pagination:     return null;   // ← fetch-all → always meta.total (§5)
```

The argument is the **resolved server-default paginator**, not the whole `Server` — the
method needs nothing else, and resources stay decoupled from `ServerInterface` (widen to
the `Server` later only if a resource ever needs more server context). The resolver
computes the server default once and passes it in; whatever `pagination()` returns is the
effective paginator (or `null`).

**Relations** mirror this: `AbstractRelation::pagination(?PaginatorInterface $fallback)`
returns `$this->relationPaginator ?? $fallback`, where `$fallback` is the already-resolved
related-resource-or-server default; a relation opts out explicitly with
`withoutPagination()` (returns `null` regardless of the fallback). The chain stays
*relation → related resource → server default*, but every level can now also say "no
pagination."

## 6. Combination matrix

Domain: `articles`, with a to-many `comments`. `N` = the total. "fetch-all" = no
resolved paginator.

### 6a. Primary collection — `GET /articles?page[size]=2`

`_self_` gated by the **`articles` resource** `countable()`.

| Resolved paginator | Request | Rendered |
| --- | --- | --- |
| **fetch-all** (no paginator) | any | `meta.total: N` (no `page` meta) — always |
| count-free (default) | — | windowed; **no total**; `next` via `hasMore` |
| count-free (default) | `?withCount=_self_` (countable + profile) | `meta.total: N` **and** `meta.page.total: N` + `links.last` |
| count-free | `?withCount=_self_`, **not** countable | `400` (unrecognised/rejected) |
| **`withCount()`** | any | `meta.total: N` **and** `meta.page.total: N` + `links.last` — always |

```jsonc
// count-free default (no withCount)         // ?withCount=_self_  OR  withCount() paginator
{ "data": [ … ],                             { "data": [ … ],
  "links": { "next": "…" },                    "links": { "next": "…", "last": "…" },
  "meta": { "page": {                          "meta": { "total": 47, "page": {
    "currentPage": 1, "perPage": 2 } } }         "total": 47, "currentPage": 1, "perPage": 2 } } }
```

### 6b. Relationship — related endpoint — `GET /articles/1/comments?page[size]=2`

Same shape as a primary collection (the comments *are* the primary data here). `_self_`
gated by the **`comments` relation** `countable()`; the paginator resolves
relation → related-resource → server.

| Resolved paginator | Request | Rendered |
| --- | --- | --- |
| fetch-all | any | `meta.total: N` — always |
| count-free (default) | — | windowed; no total; `next` only |
| count-free | `?withCount=_self_` (relation countable + profile) | `meta.total: N` + `meta.page.total: N` + `last` |
| count-free | `?withCount=_self_`, relation not countable | `400` |
| `withCount()` (on the relation's paginator) | any | `meta.total: N` + `meta.page.total: N` + `last` — always |

### 6c. Relationship — inline on a resource/collection read — `GET /articles/1` (or `/articles`)

The `comments` **relationship object** (linkage + optional `meta.total`). No pagination
here (a relationship object isn't windowed outside the profile — §6d), so `meta.page.*`
never appears; only `relationships.comments.meta.total`.

| Relationship state | Request | Rendered relationship object |
| --- | --- | --- |
| lazy (links-only), countable | — | `links` only |
| lazy, countable | `?withCount=comments` (+ profile) | `links` + `meta.total: N` (grouped count, no N+1) |
| lazy, **not** countable | `?withCount=comments` | `400` |
| materialised (`withData`/included) with **no paginator** | any | `links` + `data` + `meta.total: N` — free, always (§5) |
| materialised, **has** a paginator | — | `links` + `data`, no total (paginator ⇒ opt-in) |
| materialised, has a paginator, countable | `?withCount=comments` | `links` + `data` + `meta.total: N` |

```jsonc
// GET /articles/1?withCount=comments  (comments countable, profile negotiated)
"relationships": { "comments": {
  "links": { "self": "…/relationships/comments", "related": "…/comments" },
  "meta": { "total": 12 }
} }
```

### 6d. Relationship — included, under the **Countable** + Relationship-Queries profiles — `GET /articles/1?include=comments`

The relationship renders page-1 linkage + pagination **links**, and `included` carries
the page-1 resources. The include path follows the **same rule as §6a/§6b**: a windowed
include is **counted only when the pagination counts** — the relation's paginator opted in
(`withCount()`) or the client named the relation in `?withCount`. (This *supersedes* the
slice-1 "a `countable()` relation always counts on include" of bundle ADR 0053 — that
auto-count is gone.) The inline `meta.total` follows §6c.

| Relation | `?withCount=comments` | Relationship object (windowed include) |
| --- | --- | --- |
| fetch-all (no paginator) | — | full linkage + `meta.total: N` (free); all of `comments` in `included` |
| paginated (default) | — | `first` `prev` `next` (no `last`, no total) — even when `countable()` |
| paginated, `countable()` | yes | `first` `prev` `next` `last` + `meta.page.total` + `meta.total` |
| paginated, `withCount()` paginator | any | `first` `prev` `next` `last` + `meta.page.total` — always |

> Without the Relationship-Queries profile, `?include=comments` renders the relationship's
> full linkage + all included resources (no windowing) — so it is a fetch-all and carries
> `meta.total` per §5.

## 7. Decisions (resolved 2026-06-18)

1. ✅ **Breaking default flip** — all paginators count-free by default. Accepted (pre-1.0).
2. ✅ **Profile rename** *Relationship Counts → Countable* (keyword `countable`); the
   `?withCount` param name stays. Includes the published spec doc + URI, the negotiation
   keyword, and the core/bundle ADR updates.
3. ✅ **`_self_` token** — use `_self_`; a relation literally named `self` would be odd, but
   the parser must still guard the collision (a relation named `_self_` is rejected / the
   token wins — fix precedence at build).
4. ✅ **Both slots** — render the same number in `meta.total` **and** `meta.page.total`
   (when paginated), from one count.
5. ✅ **No-paginator reachability** — `pagination()` becomes the single source of truth
   (a `null` return = no pagination) and **receives the resolved server-default paginator
   as an argument**; the base impl returns it (see §5). Pass the paginator, not the whole
   `Server`. Relations mirror via `withoutPagination()`.

## 8. Scope (core + bundle, ~Solution-A sized)

- **Core:** `PaginatorInterface::wantsCount()` + `withCount()` on the non-cursor
  paginators; `countable()` on `AbstractResource`; **`pagination(?PaginatorInterface
  $serverDefault)` signature change** (return value verbatim, `null` = no pagination) +
  `AbstractRelation::withoutPagination()`; the `_self_` token in the `?withCount` parser;
  rename the Countable profile + its spec doc/URI.
- **Bundle handler:** compute the total once and fan it to `meta.total` + `meta.page.total`;
  add the primary `paginateWithoutCount` branch (today: `total!==null ? paginate :
  fromCollection`); the fetch-all → always-`meta.total` path; wire `_self_`.
- **Providers (Doctrine + in-memory):** count-free fetch (skip `COUNT`, fetch window+1 for
  `hasMore`); reuse the single count for both meta slots.
- **Tests:** budget witnesses (count-free paginator ⇒ 0 `COUNT`; `withCount()` ⇒ exactly
  1; fetch-all ⇒ `meta.total` with no extra query); matrix conformance on both providers.
- **ADRs + docs:** core + bundle; pagination.md / relationships.md tables; the renamed
  profile spec.
