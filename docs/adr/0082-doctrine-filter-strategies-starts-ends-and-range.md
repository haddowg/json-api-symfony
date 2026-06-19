# Doctrine `starts`/`ends` operators, the `Range` push-down, and deepObject docs

The Doctrine `DoctrineFilterHandler` now executes the rest of the convenience filter
library (core ADRs 0075-0077) the in-memory handler already proves. The two new
`starts`/`ends` string operators (backing `StartsWith`/`EndsWith`) reuse the existing
contains-`LIKE` helper, generalised to wrap the escaped value with the `%` wildcard
on the matching side only — `LIKE 'v%'` for `starts`, `LIKE '%v'` for `ends`, both
`LOWER()`ed and `ESCAPE '!'`-escaped exactly like contains, so they mirror the
in-memory `stripos === 0` / `str_ends_with` ASCII case-fold. The structured
`Range`/`DateRange` filter gets a dedicated `instanceof Range` arm applying two
push-down `>=`/`<=` predicates over the present bounds on the **same** primary query
— one query, no join, no subquery and no relation load (verified by a query-budget
test); a blank (`''`) or absent bound is treated as absent, byte-for-byte with the
in-memory `bound()`, so an open-ended range works and `filter[<key>][max]=` is a
no-op rather than a `400`.

The bundle `FilterValueValidator` gained a Range-aware member path: a structured
`{min?, max?}` value is decomposed into its **present, non-blank** bounds, each
validated individually against the range's preset per-bound constraint (a numeric
`Pattern` for `Range`, an ISO-8601 `Pattern` for `DateRange`). So a blank/open bound
is never validated (no false `400`) while a malformed present bound is a clean
`FILTER_VALUE_INVALID` `400` — on **both** providers, since the validator runs
pre-provider on the requested filter map. No new core constraint type was needed:
`DateRange` rejection rides core's preset ISO-8601 `Pattern`.

A `DateRange` bound also gets a **temporal-validity** check on top of the shape
`Pattern`. A regex cannot reject a calendar-invalid date — `1997-13-99` (month 13,
day 99) matches the deliberately-lenient ISO-8601 shape but does not parse — so the
validator additionally runs each present bound through the filter's own date
deserializer and rejects a value that does not coerce to `\DateTimeInterface` as the
same clean `FILTER_VALUE_INVALID` `400`. Without this a calendar-invalid bound would
reach the data layer as a raw string and select **divergently**: the in-memory handler
would compare a `\DateTimeImmutable` column against it lexically (PHP coercion → a
full-set match) while Doctrine bound a non-date string (no rows on SQLite, a driver
`500` on a strict timestamp column). The two handlers' `range()`/`bound()` carry the
same guard as a belt-and-braces fallback (when the optional validator is absent): a
`DateRange` bound that does not coerce to `\DateTimeInterface` is skipped (treated as
open) rather than compared, so both providers degrade identically instead of diverging.

The OpenAPI projection (core) renders a `Range`/`DateRange` parameter as an OAS
`deepObject` (`style: deepObject, explode: true`) over its `{min, max}` object value
schema, and every `filter[<key>]` parameter now carries the filter's own declared
description; the example `albums` resource declares a `Contains` title search, a
numeric `Range` over `averageRating` and a `DateRange` over `releasedAt` as the
worked Doctrine witness.
