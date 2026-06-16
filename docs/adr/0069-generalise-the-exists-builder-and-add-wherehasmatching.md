# Generalise the relationship-EXISTS builder and add a Doctrine `WhereHasMatching` escape hatch

`DoctrineFilterHandler::whereHas` already pushed a relationship-existence filter
down as a **correlated `EXISTS` subquery** (set-membership, not a fetch-join, so the
primary rows are neither multiplied nor need a `DISTINCT`, a to-one and a to-many
translate identically, and linkage / `?include` / the relationQuery profile compose
for free because the relation is never hydrated). The portable
[`WhereThrough`](https://github.com/haddowg/json-api) core VO (core ADR 0063) adds a
**dotted traversal** — `filter[author.name]` keeps a row whose author's name
matches, `filter[author.company.name]` chains the hops, `EXISTS-ANY` across both
to-one and to-many arities. Rather than add a second, parallel subquery path we
**generalised the one EXISTS builder** (`existsSubquery()`) to serve **three
front-ends** off the same construction:

- **`WhereHas` / `WhereDoesntHave`** — the degenerate length-1 path: the
  relationship is the only hop, no leaf predicate, pure existence (negated for
  `WhereDoesntHave`). Both core declarations are KEPT; only the implementation
  folded into the shared builder.
- **`WhereThrough`** — a dotted path: the intermediate segments chain as inner
  joins off the related root, the final segment compares as the leaf via the SAME
  comparison body as a `Where` column (`applyComparison()` — identical operator
  vocabulary and `like` = case-insensitive-contains), `EXISTS-ANY`.
- **`WhereHasMatching`** — a NEW **bundle-only** Doctrine VO (below).

`existsSubquery(QueryBuilder $query, list<string> $segments, ?\Closure $applyLeaf)`
roots the subquery on the **RELATED entity** (the first segment's association
target) and correlates back to the outer owner by a membership `IN`-subquery on
the owning association — `related.id IN (SELECT m.id FROM Owner o JOIN o.<firstHop> m
WHERE o = <outerRoot>)`, mirroring `RelationScope`'s subquery branch. Rooting on the
related entity (rather than root-on-owner + join) is what lets an author predicate —
`addCriteria` or a closure — hang naturally off the related root; the membership
correlation is uniform for to-one, to-many, owning-side and many-to-many (it never
needs the inverse field). Each segment's kind resolves from `ClassMetadata` at build
time: intermediate segments must be `hasAssociation()` (a typo or an attribute-mid-path
is a loud `LogicException`), the leaf must be `hasField()`. `$applyLeaf` (null for pure
existence) receives `(subquery, leafAlias, leafField)`; the `WhereThrough` leaf adds
its predicate TEXT on the subquery but binds the placeholder NAME and value on the
OUTER query — so a placeholder collision across several traversal filters on one query
cannot happen.

`WhereHasMatching` is the **20% escape hatch** the portable vocabulary cannot
express (multi-column / `OR` / `NOT`, or raw DQL). It is a SIBLING bundle VO under
`haddowg\JsonApiBundle\DataProvider\Doctrine\Filter\` implementing core's
`FilterInterface` — **not** a subclass or decorator of core's `final readonly
WhereHas` (core stays storage-agnostic and unaware of it). Two surfaces:
`WhereHasMatching::criteria($key, $relationship, Criteria $criteria)` applies a
`Doctrine\Common\Collections\Criteria` with `addCriteria` on the related root, and
`WhereHasMatching::using($key, $relationship, \Closure(QueryBuilder $sub, string
$relatedAlias, mixed $value): void)` is the deep hatch with raw subquery access,
parameterised by the request value (the author owns correctness and binding). Both
feed the SAME `existsSubquery()` (a single relationship hop, no leaf). It is
recognised by a new `instanceof` arm in `DoctrineFilterHandler::applyOn`'s
`match(true)`.

`WhereHasMatching` is **not portable and not value-validated**: `constraints()`
returns `[]` (the author owns the value), and it is registered **only on a Doctrine
resource's `filters()`** — never the in-memory handler. So on the in-memory provider
the requested `filter[<key>]` key is undeclared and `CriteriaApplier` raises
`FilterParamUnrecognized` → a clean **`400`** (the same unrecognised-filter boundary
the `pivot.` prefix uses — NOT `UnsupportedFilter`, which is a `500`). This is the
deliberate boundary: a portable traversal (`WhereThrough`) works on both providers
byte-for-byte; a Doctrine-only escape hatch is a `400` everywhere else.

**Out of scope:** sort traversal (`sort=author.name`) genuinely needs a join (you
cannot order by a subquery column) and the row-multiplication/`DISTINCT` concern it
reopens — a separate later slice. G8 stays filter-only on `EXISTS`.
