# G8b — ready-made filter library — design spec

> Scratch design doc (untracked, like the OpenAPI/g13/g21 ones). Committed record =
> a docs/filtering section. Status: **agreed with
> Greg 2026-06-19**, pending build.

## 1. Goal

Ship a small set of **intent-named convenience filters** so authors declare what they
mean (`Contains`, `GreaterThanOrEqual`, `Range`, `DateRange`, `Boolean`, …) instead of
hand-wiring `Where` operators, value coercion, and OpenAPI value schemas. Closes G8b;
the hard half (dot-notation relation traversal — `WhereThrough`/`WhereHasMatching`) is
already shipped, so this is the convenience-strategy layer on top.

## 2. Honest baseline — what `Where` already does (don't re-build)

Both handlers (in-memory `ArrayFilterHandler::compare()`, Doctrine `DoctrineFilterHandler`)
already support, on a plain `Where`:
- comparison operators `=`/`==`/`===`/`!=`/`<>`/`>`/`>=`/`<`/`<=`
- a **contains**-`like` (in-memory `stripos`, Doctrine `LIKE '%…%'` — wildcards added by
  the handler, NOT leaked to the client)
- value constraints (`->integer()`/`->boolean()`/`->numeric()`/…) and `->deserialize()`

So `Where::make('name','name','like')` and `Where::make('age','age','>=')` already work.
**The convenience layer is NOT a new query engine.** The genuine gaps it closes:
1. **Value coercion** — `Where::make('age','age','>=')` compares the raw **string** `'18'`,
   so `'18' >= '5'` is a *string* comparison (footgun, esp. in-memory). The conveniences
   bake in type coercion (int/float/bool/`\DateTimeImmutable`) + the matching constraint.
2. **Prefix/suffix match** — only contains-`like` exists; `StartsWith`/`EndsWith` need two
   new operators.
3. **Structured ranges** — no min/max-in-one-key; `Range`/`DateRange` are genuinely new.
4. **OpenAPI value schemas** — a bare `Where` projects a generic string param; an
   intent-named filter projects the right type + a human description (and `Range` a
   deepObject), so the docs we just shipped describe filters precisely.

## 3. DX

```php
// strings
Contains::make('name');            // ?filter[name]=ada    → contains (= Where 'like', + string schema)
StartsWith::make('sku');           // ?filter[sku]=AB      → LIKE 'AB%' / str_starts_with   (NEW operator)
EndsWith::make('domain');          // ?filter[domain]=.io  → LIKE '%.io' / str_ends_with     (NEW operator)

// scalar comparison — coercion + validation + numeric/date schema baked in
GreaterThan::make('age');          // coerces to number, '18' > '5' done numerically
GreaterThanOrEqual::make('age');
LessThan::make('price');
LessThanOrEqual::make('price');
Numeric::make('rating');           // = with numeric coercion+validation (kills string-compare footgun)

Boolean::make('active', 'is_active');   // truthy-string → bool, boolean schema

// structured (NEW filter type — two predicates, one key)
Range::make('price');              // ?filter[price][min]=10&filter[price][max]=100  (either bound optional)
DateRange::make('published', 'published_at');   // ISO-8601 → \DateTimeImmutable, after/before in one key
```

All keep the existing `Where` ergonomics: optional second arg = backing column
(`->storedAs` style), `->describedAs()`, `->example()`, `->default()`, relation-scoped
use, etc.

## 4. Implementation shape (composition vs new)

**A. `Where` subclasses — near-free** (preset operator + coercion + value schema; the
handlers' existing `instanceof Where` arm catches them, zero handler change):
- `Contains` (op `like`), `Numeric`/`GreaterThan`/`GreaterThanOrEqual`/`LessThan`/
  `LessThanOrEqual` (existing ops + numeric/date coercion + constraint), `Boolean`
  (op `=` + bool coercion).

**B. Two new operators** (`starts`, `ends`) added to `ArrayFilterHandler::compare()` (core)
and `DoctrineFilterHandler` (bundle) — then `StartsWith`/`EndsWith` are `Where` subclasses
presetting them. Small, additive match arms.

**C. One genuinely new filter type — `Range`/`Between`** (and `DateRange` extends it with
date coercion): implements `FilterInterface` with a **structured value** (`{min?, max?}`),
applying two predicates. Needs a new apply arm in **both** handlers (in-memory:
`min <= v <= max` over present bounds; Doctrine: two `andWhere`). Accepts a single open
bound. `DateRange` coerces each bound ISO-8601 → `\DateTimeImmutable`.

**D. Value coercion seam:** reuse the existing `Where::deserialize` mechanism; the
conveniences preset a typed deserializer (and the matching `->integer()`/`->numeric()`/…
constraint) so coercion + validation + the OpenAPI schema all line up from one declaration.

## 5. Wire shape (decided)

- Scalar filters: `?filter[<key>]=<value>` (unchanged).
- **`Range`/`DateRange`: nested** — `?filter[<key>][min]=…&filter[<key>][max]=…` (either
  bound optional, so open-ended ranges work). Chosen over a delimited `10,100` because it
  is self-documenting, handles open bounds, and maps to an OpenAPI **`deepObject`** param.
  (Symfony already parses the nested query into `['min'=>…, 'max'=>…]`.)

## 6. OpenAPI integration (the payoff)

Each convenience filter declares its **value schema** so the generator (just shipped)
documents the `filter[...]` param precisely: `Contains`/`StartsWith`/`EndsWith` → string;
`Numeric`/comparison → number; `Boolean` → boolean; `DateRange` bounds → `string,
format: date-time`; `Range`/`DateRange` → an **object** value rendered as a `deepObject`
parameter with `min`/`max` properties. A short description per strategy ("matches values
≥ …", "contains …"). The scalar conveniences get this through the value constraints they
preset (the projector already reads filter constraints); `Range` needs a small projector
arm to emit the deepObject param.

## 7. Layering

- **Core:** the filter VO classes (`Where` subclasses + `Range`/`DateRange`), the two new
  in-memory operators, the `Range` in-memory apply, the preset coercion/constraints, and
  each filter's value-schema declaration. Framework-agnostic, rides the existing
  `FilterInterface`/`ArrayFilterHandler`.
- **Bundle:** the two new Doctrine operators, the `Range` Doctrine apply (push-down
  `andWhere` ×2), the OpenAPI deepObject projector arm, example-app wiring, docs.

## 8. Slice plan (thread-orchestrated workflows, gated, adversarial review)

- **Slice 1 — core.** All convenience filter VOs + coercion + value schemas; `starts`/`ends`
  operators + `Range`/`DateRange` in-memory apply; dual-direction + footgun-coercion tests
  (prove `'18' > '5'` is numeric).
- **Slice 2 — bundle.** Doctrine arms for the new operators + `Range`; the OpenAPI deepObject
  param projection + per-strategy descriptions; example-app filters (a `Contains` search, a
  `Range` price, a `DateRange`) + dual-provider functional + an N+1/query-budget check on
  `Range` (two predicates, one query); docs/filtering section.

## 9. Acceptance

- Each strategy filters correctly on **both** providers; coercion makes numeric/date
  comparisons numeric/temporal (not string), proven by a test that fails under a bare `Where`.
- `Range`/`DateRange` apply min/max in one key with open bounds, one query (no N+1).
- The generated OpenAPI documents each `filter[...]` with the right type + description, and
  `Range` as a `deepObject` param.
- Filters compose with relation-scoped filtering + the existing `Where*` vocabulary.
- PHPStan L9 + PER-CS 2.0 green on both repos (8.3 + 8.4 + lowest-deps); spec-grouped suites
  green on both providers.

## 10. v1 strategy set (agreed)

`Contains`, `StartsWith`, `EndsWith`, `GreaterThan`, `GreaterThanOrEqual`, `LessThan`,
`LessThanOrEqual`, `Numeric`, `Boolean`, `Range`, `DateRange`. (Equality is already
`Where`/`WhereIn`; `In` is `WhereIn` — not re-shipped.)
</content>
