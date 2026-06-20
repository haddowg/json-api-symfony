# Laravel-vs-ours gap — actionable build plan

The architecture-lens re-classification of the 73-row Laravel gap table. Each row
was tested against the actual seams that exist today (`CrudOperationHandler`,
`DataProvider`/`DataPersister`, `DoctrineExtensionInterface`, the Validator bridge,
the relation DSL, core's testing primitives) and re-classified as **real** (a
genuine build), **folds** (delivered by another build, no standalone work),
**recipe** (a consumer can already assemble it from a seam → docs), or
**already-have / N-A**.

This document is the **scope contract**: the build list below is everything we
build before v1. Everything else is a doc recipe, an already-shipped capability,
or a conscious omission.

---

## 1. THE BUILD LIST (real gaps — build before v1)

Sorted by **value** (high → low) then **effort** (S → L). `layer`: core /
bundle / both. Deduped: the `folds` rows are pulled out of this list (see §2),
and testing-utility rows that are one assertion in a cluster are merged.

### High value

| #    | Feature                                                           | Value | Effort | Layer  | Why |
|------|------------------------------------------------------------------|-------|--------|--------|-----|
| 8    | `defaultSort()` applied when no `?sort`                          | high  | S      | both   | Unsorted collection is storage-order today; cheap deterministic hook on the resource, applied by `CriteriaApplier` when sort is empty. |
| 9    | Max-per-page cap (`withMaxPerPage`)                              | high  | S      | core   | No clamp exists; one central `min(requested,max)` in the paginators protects every store from a page-size DoS. |
| 1    | **Per-operation lifecycle hooks** (saving/saved/creating/created/updating/updated/deleting/deleted + per-relationship; after-hook may return a Response) | high  | M      | both   | No author extension site exists between fetch and commit; the keystone — unlocks authz (#3), delete-guard (#16), custom-action shaping, imperative escapes. Build first. |
| 6    | Resource-object self link by convention (`uriType`+id+baseUri, `withoutSelfLink()` opt-out) | high  | M      | both   | `getLinks()` returns null by default; spec-recommended affordance every other impl ships. Genuine convention build. |
| 9b   | **Include-path safeguard** (`$maxDepth` + per-relation `cannotBeIncluded`) — *already-designed/queued* | high  | M      | both   | ADR 0035 preloader is built but unbounded; ship the already-designed bound. No maxDepth/cannotBeIncluded exists anywhere. |
| 3    | **Per-operation authorization** seeing the loaded entity (declarative bundle security on the resource attribute, riding #1) | high  | L      | bundle | Reverses the entity-authz non-goal *knowingly*: a before-write hook (#1) + read row-hiding via `DoctrineExtension` (#5 recipe) + the `AccessDeniedException`→403 listener arm (#38). The declarative-security layer is bundle-only sugar over #1. |
| 10   | Countable to-many relations (`canCount`, `?withCount`, `meta.count`) — rides #13 | high  | L      | both   | Pushed-down COUNT + `?withCount` parse + `countRelated()` provider seam is a genuine build; the meta-render half folds into #13. |
| 11   | Status + content-type-aware fetch assertions (`assertStatus`/`assertContentType`) | high  | M      | both   | `JsonApiDocument` asserts body only; the bundle harness has no JSON:API response wrapper. Anchors the testing-utility cluster (#11a/b/c, #44, #45, #46, #50, #62, #63). |
| 11a  | Exact-match document assertions (`assertFetchedOneExact`, `assertExactMeta/Links/Errors`) — carries #64 pretty-diff | high  | M      | core   | Only partial-match assertions exist, so leaked fields are invisible. Pretty-printed key-sorted JSON diff (#64) is free here. |
| 11b  | Collection assertions (`assertFetchedMany`/`InOrder`/`Exact`)   | high  | M      | core   | No collection set/ordered assertion exists; the only first-class witness for `?sort`. |
| 11c  | Created-resource assertion bundle (201 + Location + id + body)   | high  | M      | both   | Nothing witnesses the create contract (status + Location header + id) as a unit. |

### Medium value

| #    | Feature                                                           | Value | Effort | Layer  | Why |
|------|------------------------------------------------------------------|-------|--------|--------|-----|
| 44   | Error-status assertion (`assertResponseStatus`) — thread response | med   | S      | both   | `Decode::toArray` discards the response; thread it so HTTP status and error object assert as a unit. (Corrects the table's "already holds the PSR-7 response" premise.) |
| 45   | `assertHasExactError` / whole-array error match                  | med   | S      | core   | Current error assertions are subset-only over status/pointer/code; no whole-member exact compare. |
| 17   | Conditional `readOnly(fn)`/`hidden(fn)` via closure              | med   | S      | core   | `readOnly`/`hidden` are static bools; closure overloads gate writes/omission per request. |
| 40   | `identifierMeta()` — meta on a resource identifier distinct from resource-object meta | med   | M      | core   | Both identifier sites reuse `getMeta()`; add `getIdentifierMeta()` (default = empty/reuse) and emit on the identifier object. |
| 4    | Filter-value validation seam (`filter[createdAfter]=banana` → 400/422) | med   | M      | both   | Manual escape hatch exists (`deserializeUsing()` throwing `QueryParamMalformed`); the missing part is the *declarative/automatic* seam reusing the Validator bridge — without it an unguarded coercion closure 500s. |
| 13   | Per-relationship meta builder (`meta()`/`withMeta()`) — unblocks #10 | med   | M      | core   | Relationship VO already renders meta; `AbstractRelation::buildRelationship()` is a stub that never threads a declarative `meta()`. |
| 24   | Sort by relationship count (`SortCountable`/`countAs` → SQL COUNT ORDER BY) | med   | M      | both   | `DoctrineSortHandler` throws on anything but `SortByField`; push down a correlated COUNT (reusing the `WhereHas` EXISTS machinery). |
| 26   | Dynamic `baseUri()` callback (multi-tenant per-request host)     | med   | S      | both   | `Server` memoizes one static `baseUri`; surface a callable/resolver variant as a service-id config option. |
| 27   | Disallow / require pagination (force or forbid `page[]`)         | med   | M      | both   | We default+clamp but can't *reject* an unpaginated request or 400 `page[]` on an unpaginated resource. Pairs with #9. |
| 28   | Simple / no-total pagination (`withSimplePagination`) for the page family | med   | M      | core   | `PageBasedPage` always computes total/lastPage and the provider always runs COUNT; no count-free page-number strategy. |
| 37   | Reject sparse fieldsets for unknown type/field (opt-in `allowedFieldSets`) | med   | M      | both   | `parseIncludedFields` silently ignores unknown type/field; opt-in 400 guard mirroring `?include`, lenient default kept. |
| 38   | Extensible exception→JSON:API pipeline (tagged exception-mapper seam) | med   | M      | bundle | `toErrorResponse` is a fixed 3-arm match; a first-match tagged mapper before the 500 fallback. Also enables the #3 `AccessDeniedException`→403 mapping. |
| 42   | Localisation of spec/exception error objects + locale negotiation | med   | M      | both   | Built-in exceptions hard-code English title/detail with no key seam; add a message-key seam (core) + `TranslatorInterface` + `Accept-Language` (bundle). |
| 43   | Polymorphic to-many shared filter/sort vocabulary               | med   | M      | both   | `MorphToMany::types()` declares members only; a declarable+executable shared filter/sort set is absent (render already ships). |
| 46   | Typed test query DSL (`includePaths`/`sparseFields`/`filter`/`sort`/`page`) | med   | M      | both   | Builder has only `withQueryParam`; no typed bracket-encoding. Sequence under the #11 testing slice. |
| 47   | `expects(type)` model-to-resource assertion binding (`expectResource(object)`) | med   | L      | bundle | Nothing exists; needs serializer-resolution-from-entity plumbing (bundle-only, core has no model concept). |
| 15   | PATCH merge-before-validate (`withExisting`)                    | high  | M      | bundle | Document-first `validate()` skips `CompareField`/`When` when a sibling is absent; route those rules through the merged post-hydration pass (extends the `validateEntity` seam). |
| 22   | Attribute flattened from a related model (write-back into a 1:1 related entity) | low   | L      | both   | Read-half is a recipe; write-back needs real machinery. Core marks `Map::on($relation)` out-of-scope for 1.0 — **track only, defer past v1.** |

### Low value (build only if cheap / opportunistic)

| #    | Feature                                                           | Value | Effort | Layer  | Why |
|------|------------------------------------------------------------------|-------|--------|--------|-----|
| 58   | `Error::fromArray()` factory                                    | low   | S      | core   | Named-arg construct-only; pattern already on `ResourceIdentifier`. Eases building from config/external arrays. |
| 59   | `is4xx()`/`is5xx()` on the exception interface                  | low   | S      | core   | Trivial boolean re-expose over `getStatusCode()` (rounding already in `nearestClass`). |
| 60   | `withoutHeaders()` response wither                              | low   | S      | core   | Pure symmetry — `AbstractRequest` already ships `withoutHeader`. |
| 62   | `id()`/`type()` accessors on the test document                  | low   | S      | core   | Cheap DX for create-then-fetch chaining (workaround via `data()['id']` today). |
| 63   | Meta-only / absence assertions (`assertNoData`/`NoMeta`/`NoLink`) | low   | S      | core   | Witnesses `withoutLinks()` suppression and meta-only endpoints. Trivial. |
| 66   | `alwaysShowData()` — force linkage data under a deferred relation | low   | S      | core   | Trivial inverse of `dataOnlyWhenLoaded`. **Note-only.** |
| 52   | Number `acceptStrings()`/`strict()` (reject numeric strings)    | low   | S      | core   | Integer/Decimal coerce before validation; strictness must live at the field. Low value (wire-type rigor). |
| 57   | `deserializeUsing` on set filters (`WhereIn`/`WhereNotIn`)      | low   | S      | core   | `Where` has it, the set filters don't; needs new VO + handler arm (not a one-liner). |
| 61   | `report()` per-exception log-control hook                       | low   | S      | bundle | Only the 500-arm logs; no per-exception suppress/defer. Not covered by #1 or #38. |
| 39   | Validation-error pointer customisation (`withSourcePrefix`/`withPointers`) | low   | M      | bundle | `JsonPointerBuilder` is a fixed mapper; niche (wire member == property path for the majority). |
| 29   | Page meta key nesting + casing (`withMetaKey`, snake/dash)       | low   | M      | core   | `meta.page` key + camelCase keys are hardcoded; purely presentational. |

**Queued / already-designed (call out explicitly):** **#9b** include-safeguard
(ADR 0035 preloader bound), **#19** custom id route pattern and **#20** pluggable
id encode/decode (both the §4 id-encoding design — see §2 folds, they ship under
that queued work), **#31** per-relationship extra filters (relation DSL gap, see
below). The **authz direction** is settled: **bundle declarative security on the
resource attribute, riding #1** (a before-write hook) plus the #38 listener arm —
not a core entity-authz concept.

**Relation-DSL gap not in the headline table but real:**

| #    | Feature                                                           | Value | Effort | Layer  | Why |
|------|------------------------------------------------------------------|-------|--------|--------|-----|
| 31   | Per-relationship extra filters (`withFilters()` scoped to a relationship endpoint) | med   | M      | both   | `fetchRelatedCollection` sources filters solely from the related resource's `filters()`; no relation-scoped override. Genuine small DSL gap. |

---

## 2. FOLDS INTO LIFECYCLE HOOKS (#1) — and into other builds

These carry **no standalone work**; the named build delivers them.

**Into the hooks (#1):**

- **#16 Delete-guard validation** (`deleteRules`/`metaForDelete` → veto a DELETE 422/409) — a before-delete hook that throws *is* a clean delete refusal; rendered by `ExceptionListener`.
- **#3 Per-operation authorization** is **mostly** delivered by #1 (the before-write hook is the throw site for write authz on the loaded entity) — but it is listed as its own **real** build in §1 because it also requires the #5 read row-hiding recipe + the #38 listener `AccessDeniedException`→403 arm + the declarative-security bundle sugar. The *engine* is #1; the *layer* is its own slice.
- **#71 Custom 403 denial message** (`Response::deny('…')`) — no meaning until #3 exists; the denial detail is a property of the #3/#1 throw site (a custom `HttpException` detail already round-trips today, debug-gated).

**Into other builds (not #1):**

- **#19 Custom id route pattern** (`matchAs`/`ulid`) → ships under the **queued §4 id-route design** (`setRequirement('id', …)` + a trivial `ulid()` helper).
- **#20 Pluggable id encode/decode** (`IdEncoder`/HashIds) → ships under the **queued §4 id-encoding design** (an encode/decode hook so provider/persister round-trip an opaque id; HashIds stays userland).
- **#50 `assertIsIncluded(type, model)` / `assertIncludedExactly`** → one assertion in the **#11/#11a/#11b testing-utility cluster** (membership-by-id + exact-set).
- **#64 Pretty-printed key-sorted JSON diff** → free once **#11a** exact-match lands (the only site with a value).
- **#65 `asBoolean()` soft-delete projection** → a sub-feature of the **soft-delete recipe (#2)**; no life outside it.

---

## 3. DOCS RECIPES (assemblable from an existing seam — document, don't build)

A consumer can build each of these today from a seam. These are **recipe docs**,
not features. `feature → seam`.

- **#2 Soft-delete capability** → custom `DataPersister::delete()` (soft-on-delete) + request-aware `DoctrineExtension` exclusion scope + writable `deletedAt` attribute (restore) + consumer-declared trash filters. *(Only genuinely-missing primitive: a force-delete bypassing the exclusion scope — a small `QueryPurpose`/flag, the real remainder.)*
- **#5 Request/user-scoped collection filtering** (`indexQuery`/global scopes) → `DoctrineExtensionInterface` tagged service injecting `Security`/`RequestStack` and `andWhere()`ing a per-user scope (runs on FetchOne + FetchCollection + fetchRelatedCollection, so COUNT and the 404 respect it).
- **#12 Detached-model lifecycle** (`deleteDetachedModel`) → custom `DataPersister::mutateRelationship()` (owns the association write → orphan-remove on detach). *(Optional Doctrine `orphanRemoval` flag is sugar — track.)*
- **#14 Custom (non-CRUD) actions** (`-actions/publish`) → a plain Symfony controller renders core's public `DataResponse::toPsrResponse()` render seam itself.
- **#21 Eager-load the relation an attribute derives from** (N+1 safety) → `DoctrineExtensionInterface` `leftJoin`+`addSelect` (the interface documents eager-loading joins as a use case).
- **#23 Always-eager relationships** (`$with`) → `DoctrineExtension` join folded into the base query. *(Only the declarative always-load list is missing — low-value convenience.)*
- **#25 `serving()` server hook** → a stock Symfony `kernel.request` listener keyed on the `_jsonapi_server` route default (the whole lifecycle is already kernel-listener based).
- **#30 Opaque / encoded cursors** → `CursorPaginator` already takes caller-supplied `int|string` cursors; encode/decode in the provider. *(A `CursorCodec` hook would remove boilerplate, not unlock reach.)*
- **#33 Scope filter** (named query fragment) → a custom `DataProviderInterface` for the type interprets the filter VO arbitrarily (priority shadows the `-128` Doctrine fallback).
- **#34 BelongsToMany pivot fields via closure** → `computed()`/`serializeUsing()` on the join entity's serializer, or a custom provider.
- **#35 Per-resource validator escape hatch** (`withValidator`) → `EntityConstraintInterface` post-hydration seam + class-keyed `ConstraintTranslator`; message/attribute overrides via the Symfony `validators` translation domain. *(Document-level after-hook folds into #1.)*
- **#36 Per-request query-param narrowing** (`forget`/`notSupported`) → override `filters()`/`sorts()` on the resource-as-service injecting `RequestStack`/`Security`; a dropped key 400s automatically.
- **#41 Declarative conditional members** (`when()`/`mergeWhen()`) → return a request-shaped map from `getAttributes`/`getRelationships`, or `serializeUsing` (they receive the request). Pure ergonomic sugar.
- **#51 `hasOneThrough`/`hasManyThrough`** → `AbstractRelation::extractUsing()` + a custom `fetchRelatedCollection()` provider (read-only through-projection).
- **#53 Array key-case transforms** (`camelizeKeys`) → `ArrayHash` `serializeUsing`/`deserializeUsing` closures (one-liner key-case map).
- **#54 Map `ignoreNull()`** → the child's `deserializeUsing`/`fillUsing` closure (Map already skips absent keys).
- **#55 Multi-paginator** (client chooses page OR cursor) → a composite `PaginatorInterface` returned from `pagination()` that inspects which `page[]` keys are present.
- **#56 Fluent operator setters on `Where`** (`gt`/`gte`) → `Where::make($key, $column, $operator)` already takes the operator as the 3rd arg.
- **#69 Per-member-type config in one polymorphic relation** → a custom `fetchRelatedCollection` provider (the documented polymorphic escape hatch).
- **#72 Override one action by trait removal** → handler decoration via `#[AsDecorator]` (ADR 0028) intercepts one operation+type, delegates the rest; finer shaping folds into #1.
- **#73 Per-action middleware/security in the route definition** → the loader emits one literal path+name per action, so `access_control`/`IsGranted` on `jsonapi.{type}.{action}` works today (security.md). Declarative per-op security expression rides #3/#1.

---

## 4. ALREADY HAVE / N-A

**Already have (docs gap at most):**

- **#18 DateTime `retainTimezone()`** — `deserializeValue` does `new \DateTimeImmutable($value)`, retaining the wire offset by default; `useTimezone()` is the explicit normalisation toggle. Pure docs.
- **#48 Non-JSON:API / malformed request helpers** — `Content-Type` override already works (the `$headers` loop applies after the body block). Only `withRawBody(string)` is genuinely missing → folded as one trivial wither under the #46/#11 testing slice.

**N-A (conscious omission — leave unless demanded):**

- **#32 Pivot / join-table filters** (`WherePivot…`) — m2m join tables with attributes are modelled as their own filterable entity (the §3 stance); pivot columns are declare-only in 1.0, so there is nothing to filter against.
- **#67 `page[]` key validation against the paginator** — `QueryParam` clamps garbage and stays 200 by design (the deliberate clamp-not-400 stance); a strict-400 mode is a conscious-omission reversal, optional toggle only.
- **#68 Auto-derived resource type from class name** — `::$type` is the explicit registry key; auto-derivation is a conscious tradeoff, convenience-only.

---

## Scope summary

The architecture lens collapsed the original **73** rows hard. Of them, **8 are
recipes downgraded from feature** to documentation (a consumer already assembles
them from a seam), **8 fold into another build** (mostly the lifecycle hooks #1),
**2 are already-have**, and **3 are conscious N-A omissions** — leaving **~52 real
build entries**, of which the genuinely **high-value pre-v1 core** is roughly a
dozen: the lifecycle hooks (#1, the keystone that unblocks authz/delete-guard/
custom actions), the self-link and default-sort conventions (#6, #8), the
max-per-page + include-depth DoS bounds (#9, #9b), countable relations (#10,
riding #13), and the testing-utility cluster (#11/11a/11b/11c plus the folded
#44/45/46/50/62/63/64). Everything else is medium/low and opportunistic. The net
effect: **the 73-row Laravel surface reduces to one keystone build plus a small,
ordered set of conventions, DoS bounds, and a testing harness** — the rest is
already reachable through the SPI and relation seams this bundle already ships,
and becomes documentation rather than code.
