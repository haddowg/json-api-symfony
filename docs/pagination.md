# Pagination

Every collection a resource serves — the primary `GET /{type}` listing and each
related-collection endpoint — is paginated by a **paginator**: a core
[`PaginatorInterface`](https://github.com/haddowg/json-api/blob/main/docs/pagination.md)
strategy that reads the request's `page[…]` parameters and renders the matching
`links` + `meta.page`. The bundle owns *where* a paginator comes from (the
resource/server resolution and the built-in default) and *who executes the window*
(the data provider); the strategies themselves — their parameters, defaults, and
the `meta.page`/`links` shape — are core's.

This page covers the count-based strategies briefly (they are documented in full by
core) and then the **cursor (keyset)** strategy in depth, because the keyset
execution — the push-down, the NULL ordering, the staleness contract — lives in the
bundle's two data providers.

## The default: every collection is paginated

You configure nothing to get pagination. The bundle gives every server a **default
paginator** — a core
[`PagePaginator`](https://github.com/haddowg/json-api/blob/main/docs/pagination.md#the-four-strategies)
(`page[number]` / `page[size]`) whose client-controlled `page[size]` is capped at
[`json_api.pagination.max_per_page`](configuration.md#paginationmax_per_page)
(default `100`). So a collection with no per-resource `pagination()` is paginated
out of the box and protected from a page-size DoS. Set the cap to `0` to install no
built-in default (those collections then render unpaginated). The cap and the
resolution chain are detailed on
[configuration → `pagination.max_per_page`](configuration.md#paginationmax_per_page)
and
[data-layer → the effective paginator](data-layer.md).

The effective paginator follows core's **resource → server default** chain. Your
`pagination()` receives the resolved server default and its **return value is the
single source of truth**: return it (or don't override) to inherit the server
default, return a paginator to pin one for this resource, or return `null` to
**disable pagination** (the collection is fetched whole — see "No pagination" below).

```php
use haddowg\JsonApi\Pagination\PagePaginator;
use haddowg\JsonApi\Pagination\PaginatorInterface;

final class AlbumResource extends AbstractResource
{
    // 25 per page, capped at 50, for this resource only.
    public function pagination(?PaginatorInterface $serverDefault): ?PaginatorInterface
    {
        return PagePaginator::make()->withDefaultPerPage(25)->withMaxPerPage(50);
    }
}
```

> ### Pagination is count-free by default
>
> A paginated collection **windows without running the expensive `COUNT`** unless
> counting is asked for. So by default a paged response carries no `meta.page.total`
> and no `links.last` — the `next` link is driven by a cheap "is there another row?"
> probe, not a total. Opt into the total either as the **author** —
> `PagePaginator::make()->withCount()` makes *this* paginator count on every paged
> request — or let a **client** ask per request with `?withCount=_self_` on a
> [`countable()`](#counting-the-primary-collection-withcountself) resource under the
> negotiated Countable profile. When a total is computed it is rendered once in both
> `meta.total` (the universal cardinality slot) and `meta.page.total`.

## The count-based strategies (briefly)

Three of core's four strategies are **count-based**: they may emit a total and a
`last` link. They differ only in their wire parameters and key conventions — core's
[pagination → The four strategies](https://github.com/haddowg/json-api/blob/main/docs/pagination.md#the-four-strategies)
is the reference; the summary:

| Strategy | Parameters | Notes |
| --- | --- | --- |
| [`PagePaginator`](https://github.com/haddowg/json-api/blob/main/docs/pagination.md#the-four-strategies) | `page[number]` / `page[size]` | The built-in default. Page-number windowing. |
| [`OffsetPaginator`](https://github.com/haddowg/json-api/blob/main/docs/pagination.md#the-four-strategies) | `page[offset]` / `page[limit]` | The same shape with row-offset semantics. |
| [`FixedPagePaginator`](https://github.com/haddowg/json-api/blob/main/docs/pagination.md#the-four-strategies) | `page[number]` only | Server-fixed page size; the client cannot set it. |

On both bundle providers a count-based collection renders `meta.page` (with a total
where the strategy emits one) and the `first`/`prev`/`next`/`last` links core's
strategy defines; the Doctrine provider pushes the window down as a SQL
`LIMIT`/`OFFSET`. Nothing about these strategies is bundle-specific beyond that
push-down, so they are not re-documented here.

> A **related** to-many collection (`GET /{type}/{id}/{rel}`) paginates count-free
> unless the relation is `countable()` — a count-based strategy still omits the
> total/`last` for a non-countable relation. See
> [relationships → counting relations](relationships.md#counting-relations-countable-and-withcount).

## Windowed includes are bounded (`window_functions`)

Under the [Relationship Queries profile](relationships.md), a collection request can
window **each parent's** included to-many relation to page 1 (e.g. the 5 newest
comments per post). The Doctrine provider runs this as ONE bounded native
`ROW_NUMBER() OVER (PARTITION BY parent …)` query per relation — fetching only ~one
page **per parent** and the **real** per-parent total (not the page size), instead of
loading every parent's whole related set and slicing in PHP (bundle ADR 0065).

> [!IMPORTANT]
> The default (`json_api.doctrine.window_functions: true`) requires SQL window
> functions: **MySQL ≥ 8, MariaDB ≥ 10.2, SQLite ≥ 3.25, or any PostgreSQL**. On an
> older engine the first windowed include throws a `500` (logged, with a message
> naming these floors). The one-line fix is to switch to the per-parent bounded
> fallback:
>
> ```yaml
> # config/packages/json_api.yaml
> json_api:
>     doctrine:
>         window_functions: false
> ```
>
> The fallback issues one real `LIMIT`/`OFFSET` query per parent (bounded, no window
> function) and renders byte-identical documents. There is no auto-detection — the
> switch is explicit.

A **filtered** windowed include (`relatedQuery[<rel>][filter][…]`) runs as ONE bounded
native query as well, carrying the relatedQuery filter through the same DQL filter
executor the related endpoint uses (bundle ADR 0066). Only a related type with a query
extension (soft-delete / tenant / published-only) — or `window_functions: false` — takes
the per-parent bounded fallback. Either way the fetch is bounded; plain (un-windowed)
includes use the `WHERE fk IN (…)` fast-path and are unaffected. See
[the Doctrine eager-loading section](doctrine.md#eager-loading-includes-no-n1).

## Cursor (keyset) pagination

The fourth strategy,
[`CursorPaginator`](https://github.com/haddowg/json-api/blob/main/docs/pagination.md#the-four-strategies),
pages by an opaque **cursor** rather than a page number or offset, executed as a
real **keyset (seek) window** on both providers (bundle ADR 0063). It is the
strategy to reach for on a large, deep, or live collection: a cursor page never
computes a total (so there is no `COUNT`), and it does not skip-and-scan rows the
way an `OFFSET` does, so a deep page costs the same as a shallow one and a row
inserted mid-collection cannot shift a client's window past an unseen row.

It is aligned to the published
[cursor-pagination profile](https://jsonapi.org/profiles/ethanresnick/cursor-pagination/),
which the rendered page advertises.

### Enabling it

A cursor paginator is wired exactly like any other — there is no separate switch.
Return a `CursorPaginator` from a resource's `pagination()`:

```php
use haddowg\JsonApi\Pagination\CursorPaginator;
use haddowg\JsonApi\Pagination\PaginatorInterface;

final class WidgetResource extends AbstractResource
{
    public function pagination(?PaginatorInterface $serverDefault): ?PaginatorInterface
    {
        // Cursor pagination, 15 items per page (the default), capped at 100.
        return CursorPaginator::make();
    }
}
```

> The cursor strategy is **inherently count-free** — it never derives a total, so it
> takes neither `withCount()` nor `?withCount=_self_` and emits no `meta.page.total`
> or `last` link. The count-free default flip above leaves it unchanged.

`CursorPaginator::make()` defaults to **15 per page**, capped at **100**. Tune both
with the withers:

```php
return CursorPaginator::make()
    ->withDefaultSize(25)   // default page size when the client sends no page[size]
    ->withMaxPerPage(50);   // clamp an over-large page[size] down to 50 (0 = uncapped)
```

To make cursor pagination a **server's** default for every collection that does not
declare its own `pagination()`, register it as the server default paginator service
instead of per resource — see
[configuration → customising the server default paginator](configuration.md#customising-the-server-default-paginator):

```yaml
# config/services.yaml
services:
    haddowg.json_api.default_paginator:
        class: haddowg\JsonApi\Pagination\CursorPaginator
```

A resource's own `pagination()` still wins over the server default, as always.

### The wire parameters

A cursor page reads three `page[…]` parameters:

| Parameter | Meaning |
| --- | --- |
| `page[size]` | The page size — the number of items to return. Clamped to the paginator's max-per-page; omitted falls back to the default size. |
| `page[after]` | An opaque cursor token: return the page **after** this position (the `next` link's token). |
| `page[before]` | An opaque cursor token: return the page **before** this position (the `prev` link's token). |

You do **not** mint `page[after]`/`page[before]` by hand — they are opaque tokens
the server emits in the page's `next`/`prev` links. A client starts with no cursor
(`GET /widgets?page[size]=…`) and follows the links. If **both** `page[after]` and
`page[before]` are supplied, `page[before]` wins.

### The cursor follows the active sort

A cursor page is a window over a **total order**, so the cursor is built from the
request's active `?sort` — the same sort vocabulary a count-based collection
validates (an unknown sort key is the same `400` either way; see
[core fields → sortable](https://github.com/haddowg/json-api/blob/main/docs/fields.md)).
When `?sort` is omitted, the resource's `defaultSort()` applies; when neither is
present, the keyset is the primary key alone.

The bundle **automatically appends the primary key** as the final tiebreaker
column, so the order is total even when every requested sort column ties — a
non-unique sort (e.g. `?sort=category`) cannot skip or repeat a row across pages.
If the client already sorts by the id, that directive terminates the keyset and no
duplicate tiebreaker is added. The appended primary key follows the direction of
the last sort directive, so a trailing `?sort=-name` keeps the tiebreak descending
too.

### Nullable sort columns

Sorting by a **nullable** column is fully supported. NULLs are ordered as the
**largest** value, so under an ascending sort they sort **last** and under a
descending sort they sort **first** — and a page boundary can land inside the null
bucket and page into and out of it with no skipped or repeated row. Both providers
enforce this identical NULL=largest order (the Doctrine provider via a forced
`ORDER BY` with a leading `IS NULL` term, the in-memory provider via the matching
comparator), so a nullable cursor sort behaves the same on either data layer.

### The cursor token is opaque

A cursor token is a URL-safe, base64url-encoded snapshot of the boundary row's
sort-column values plus the keyset's direction — it is **opaque**: do not parse,
construct, or store it as anything but an immutable string. It is **not signed or
encrypted** (mirroring Laravel's cursor): treat it as a position token, not a
secret. Tampering is not cryptographically detected — it surfaces as a malformed or
stale `400` (below).

### What a cursor page renders

A cursor page is **count-free by design**, which shapes both its links and its
meta:

- **Links:** `first`, `prev`, `next` — and **no `last`**. Computing the last page
  would require a total count, which defeats the purpose of cursors. `next` is
  present only when more items follow; `prev` only when items precede; `first`
  always resets to the head of the list. Follow these links rather than building
  cursor URLs.
- **`meta.page`:** `perPage` (the resolved page size), `from` and `to` (the ids of
  the first and last row on the page, omitted on an empty page), and `hasMore` (a
  boolean — whether a further forward page exists, the same signal as the presence
  of `next`).

**Totals are off by default** and there is no opt-in for a cursor collection — a
cursor strategy never derives a total, so it emits no `meta.page.total` and no
`last` link. (A related to-many's count is a separate, relationship-level concern,
governed by `countable()`; see
[relationships](relationships.md#counting-relations-countable-and-withcount).)

### Worked example

A `widgets` resource paginating with `CursorPaginator`, sorted by `priority`
ascending with an `id` tiebreaker, two per page:

```http
GET /widgets?sort=priority,id&page[size]=2
Accept: application/vnd.api+json
```

```json
{
  "data": [
    { "type": "widgets", "id": "2", "attributes": { "category": "guide", "priority": 10 } },
    { "type": "widgets", "id": "7", "attributes": { "category": "guide", "priority": 10 } }
  ],
  "meta": {
    "page": {
      "perPage": 2,
      "from": "2",
      "to": "7",
      "hasMore": true
    }
  },
  "links": {
    "first": "https://example.test/widgets?sort=priority,id&page[size]=2",
    "next": "https://example.test/widgets?sort=priority,id&page[after]=eyJwcmlvcml0eSI6MTAsImlkIjo3LCJfcG9pbnRzVG9OZXh0SXRlbXMiOnRydWUsIl9kIjp7InByaW9yaXR5IjpmYWxzZSwiaWQiOmZhbHNlfX0&page[size]=2"
  }
}
```

The first page carries no `prev` (it is the head of the list) and no `last` (no
total). Following the `next` link returns the next two rows; the cursor token is
opaque — the client just follows the link. The illustrative token above decodes to
the boundary row's `priority`/`id` values and the keyset direction; the exact bytes
are an implementation detail you never depend on.

This is the worked shape the dual-provider
[`CursorConformanceTestCase`](../tests/Functional/CursorConformanceTestCase.php)
asserts byte-identical on the in-memory and Doctrine-sqlite kernels over the
`cursorWidgets` fixture.

### Cursor errors (`400`)

A cursor request fails with a `400` (rendered as a JSON:API error document with the
offending `page[…]` named in `source.parameter`) in two cases:

| Error code | When | Detail |
| --- | --- | --- |
| `CURSOR_MALFORMED` | The supplied `page[after]`/`page[before]` token cannot be decoded — not base64url, not JSON, or not the expected boundary shape. | A garbage or hand-built token. |
| `CURSOR_STALE` | A well-formed token whose keyset no longer matches the request's active `?sort` — the client **changed the sort columns** *or* **flipped a sort direction** (e.g. `?sort=name` → `?sort=-name`) while holding a cursor minted under the old order. | The token was built for a different ordering and cannot be honoured against the new one. |

Both are owned by the executing provider (it resolves the active sort to keyset
columns, so it is the only place the mismatch is visible) and are byte-identical on
both providers. The fix for a stale cursor is to restart paging from the first page
under the new sort. Note the staleness check pins **direction**, not just the column
set: flipping `?sort=category` to `?sort=-category` while reusing a cursor is stale,
because the cursor was minted under the opposite order (bundle ADR 0064).

## No pagination (fetch-all)

Return `null` from `pagination()` to **disable pagination** for a resource — its
collection is then **fetched whole**:

```php
public function pagination(?PaginatorInterface $serverDefault): ?PaginatorInterface
{
    return null;   // no pagination — fetch the whole collection
}
```

Because the whole collection is materialised, its size is already in hand, so a
fetch-all collection renders **`meta.total` unconditionally** (no extra `COUNT`
query) and carries **no `meta.page`** (there is no pagination). This keeps "no
pagination" honest: you always know the size, because you fetched all of it.

## Counting the primary collection (`?withCount=_self_`)

A paged collection is count-free by default. To let a **client** request the total
per request, mark the resource `countable()` and have them negotiate the Countable
profile and send `?withCount=_self_`:

```php
final class AlbumResource extends AbstractResource
{
    public function __construct()
    {
        $this->countable();   // opt the primary collection into ?withCount=_self_
    }
}
```

```http
Accept: application/vnd.api+json;profile="https://haddowg.github.io/json-api/profiles/countable/"

GET /albums?page[size]=2&withCount=_self_
  → meta.total: N  AND  meta.page.total: N  AND  links.last
```

`_self_` is the reserved token meaning "the primary collection". A `?withCount=_self_`
against a resource that is **not** `countable()` is rejected with a `400`
(`RELATIONSHIP_COUNT_NOT_ALLOWED`). For the author-always alternative, return
`PagePaginator::make()->withCount()` from `pagination()` — then every paged request
counts, no profile or param needed.

## See also

- Core [pagination](https://github.com/haddowg/json-api/blob/main/docs/pagination.md)
  — the strategies, their parameters/defaults, and capping the page size.
- [configuration → `pagination.max_per_page`](configuration.md#paginationmax_per_page)
  and [→ customising the server default paginator](configuration.md#customising-the-server-default-paginator).
- [data-layer](data-layer.md) — how the handler resolves the effective paginator and
  the provider executes the window.
- [relationships → counting relations](relationships.md#counting-relations-countable-and-withcount)
  — related-collection pagination and `countable()`.
