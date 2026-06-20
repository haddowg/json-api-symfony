# Laravel JSON:API vs `haddowg/json-api` (+ Symfony bundle) — feature-gap analysis

A prioritised, deduplicated survey of what Laravel JSON:API 5.x offers that our
core library and/or Symfony bundle do not, scored by value, effort, and the layer
(core / bundle / both) that owns each gap. This drives what we build before v1.

Provenance: synthesised from a per-topic comparison across schema basics, fields,
relationships, eager-loading/sorting, pagination, filters, soft-deletes, server
lifecycle/hooks, routing/controllers/actions, request lifecycle & authorization,
validation, response/errors/meta/links, advanced features, and testing utilities.
Near-duplicate findings that surfaced under multiple topics have been merged
(notably the **lifecycle hooks**, **countable relations**, **include-depth
safeguard**, **soft-delete**, and **default-sort** clusters).

> **One correction to the raw survey:** the "singular() collapse not executed" gap
> is **already done** — `CrudOperationHandler` performs the zero-to-one collapse on
> an applied singular filter (ADR 0033), on both providers. It is listed under
> *Already covered* below, not as a gap.

> **Maintainer corrections (downgrade #5 and #2 — the agents over-weighted the Laravel framing):**
> - **#5 Request/user-scoped collection filtering — COVERED, not a gap.** A
>   `DoctrineExtensionInterface` is a tagged service: inject `Security`/`RequestStack`
>   and add a per-user `andWhere()` in `apply()`. The provider runs extensions on
>   **fetchOne, fetchCollection AND fetchRelatedCollection**, and COUNT + the 404
>   respect the scope. A custom (non-Doctrine) provider is also a service and injects
>   what it needs. **→ docs recipe ("scope by current user via a DoctrineExtension"),
>   not a feature.**
> - **#2 Soft-delete — DOWNGRADE to a docs recipe (+ a small force-delete seam).**
>   Laravel ships it because **Eloquent has a stock `SoftDeletes` trait**; **Doctrine
>   has no stock equivalent** (Gedmo `SoftDeleteable` or a manual `deletedAt`). It is
>   already composable from our seams: a request-aware `DoctrineExtension` (exclusion
>   scope, skipped on `withTrashed`), a custom `DataPersister` (soft-on-DELETE, or
>   Gedmo), a writable `deletedAt` attribute (restore), and consumer-declared
>   WithTrashed/OnlyTrashed filters. A first-class capability would mean **inventing an
>   opinionated convention Doctrine itself lacks** — against the storage-agnostic
>   stance. The only genuinely-missing primitive is a **force-delete** flag (small).
> - **#3 Entity-level authorization — FOLDS INTO #1 (lifecycle hooks); not a standalone
>   feature, stays agnostic.** Write authz = a **before-write hook** (runs after the
>   entity is resolved; inject `Security`/voters, check the loaded entity, throw → 403).
>   Read authz (row hiding) = the **DoctrineExtension query-scope** (the #5 recipe;
>   scope `fetchOne` → inaccessible row 404). We ship NO `viewAny/view/update` policy
>   model — authz is just one *use* of the hook + extension seams. **Small enabler:**
>   the route-scoped `ExceptionListener` currently maps `HttpExceptionInterface`
>   (`AccessDeniedHttpException` → 403) but NOT Symfony's security `AccessDeniedException`
>   (thrown by `denyAccessUnlessGranted()`) — add that mapping (→ 403) + `AuthenticationException`
>   (→ 401) so the idiomatic pattern works in a hook (~5 lines). → so #3 = build #1 + that
>   mapping + a docs recipe; the non-goal is NOT reversed (we still don't model authz).

---

## 1. Executive summary — do these before v1

Eleven gaps stand out as genuinely worth closing before the core API freezes. They
cluster into five themes; the first three are the headline items.

### The headline three

1. **Per-operation lifecycle hooks** (`beforeSave`/`afterCreate`/`afterUpdate`/
   `afterDelete` + per-relationship variants, after-hooks may replace the response).
   *Value: high. Effort: M. Layer: both.* This is the single biggest gap and it
   surfaced independently under three topics. Today the **only** author extension
   point for "do X after creating an album" is decorating the one global
   `CrudOperationHandler` and re-dispatching on operation+type by hand. Laravel's
   `saving/saved/creating/created/...` hooks are *the* primary author-facing seam
   for side effects, audit logging, event dispatch, and response overrides. Core
   defines the interface; the bundle resolves it per type via a tag. **This unlocks
   several other gaps** (delete-guard validation, custom-action response shaping,
   the imperative validation escape hatch, and an authorization callback site).

2. **Soft-delete capability** (opt-in `SoftDelete` field → DELETE soft-deletes,
   PATCH `deletedAt:null` restores, `WithTrashed`/`OnlyTrashed` filters, force-delete,
   trashed-GET policy). *Value: high. Effort: L (whole cluster). Layer: both.* Ten
   separate survey rows collapse to one capability. Soft-deletes are a near-universal
   real-API requirement and today every type hand-rolls a `DoctrineExtension` + custom
   persister. One opt-in declaration should drive exclusion-scope, restore,
   force-delete, and the two trash filters. Ship it as **one cohesive slice**, not
   ten micro-features. (The lifecycle-event half is free — Doctrine fires its own.)

3. **Per-operation authorization seam** that sees the **loaded entity**
   (`viewAny/view/create/update/delete`, + per-relationship `attach/detach`).
   *Value: high. Effort: L. Layer: bundle.* Our `security.md` deliberately punts to
   the Symfony firewall + `access_control` + voters — which gates a route/path but
   **cannot answer "can this user update THIS album"** because the entity isn't loaded
   yet. Add an optional per-type authorizer invoked inside `CrudOperationHandler`
   after target/model resolution, before validate/hydrate, throwing `AccessDenied`→403.
   Naturally rides the hooks seam's before-hook site. *Note: this is a scoping
   decision — entity-level authz has been a conscious non-goal; landing it means
   reversing that. Worth doing because it is the most-requested thing the firewall
   genuinely can't express.*

### The supporting set

4. **Filter-value validation seam** (`filter[createdAfter]=banana` → 400/422, not a
   Doctrine/PDO error). *Value: high. Effort: M. Layer: both.* Laravel treats filter
   validation as expected practice. Reuse the existing Symfony Validator bridge so
   core declares the rule metadata and the bundle executes it.

5. **Request/user-scoped collection query filtering** (Laravel `indexQuery()` /
   `relatableQuery()` / global scopes). *Value: high. Effort: M. Layer: bundle.*
   `fetchOne()`/`fetchCollection()` receive no request/auth context (only server-side
   criteria), so per-user row scoping has no clean seam — yet `fetchRelatedCollection`
   *already* takes the request. Thread `JsonApiRequest` (or a tagged query-scope) into
   both. Pairs with #3 (you usually want both row-hiding and verb-gating).

6. **Resource-object `self` link by convention** (auto-emit from `uriType`+id+baseUri,
   `withoutSelfLink()` opt-out). *Value: high. Effort: M. Layer: both.* Today a resource
   emits **no** top-level self link unless the author hand-writes `getLinks()` — the
   inverse of every battle-tested JSON:API impl and a spec-recommended affordance.

7. **Default sort for a collection** (`defaultSort()` applied when no `?sort`).
   *Value: high. Effort: S. Layer: both.* `allSorts()` only governs which sorts are
   *accepted*, never a default ordering — so an unsorted collection is storage-order.
   Cheap, deterministic, high-value. (Merges two duplicate survey rows.)

8. **Max-per-page cap** (`withMaxPerPage(int)` clamping resolved size). *Value: high.
   Effort: S. Layer: core.* Without it `page[size]=1000000` is silently honoured —
   a DoS vector. One central clamp protects every store. Pairs with our deliberate
   "clamp-not-400" pagination stance (we stay 200, we just stop honouring absurd sizes).

9. **Include-path safeguarding** (`$maxDepth` default + per-relation
   `cannotBeIncluded()`). *Value: high. Effort: M. Layer: both.* **Already designed and
   QUEUED** in project notes; the batch preloader is built (ADR 0035) but the bound is
   not landed — so `?include=a.b.c.d` fan-out is currently unbounded. **Ship the queued
   design**, don't re-scope.

10. **Countable to-many relations** (`canCount()` + `?withCount=` → `meta.count`,
    COUNT pushed down, no materialisation). *Value: high. Effort: L. Layer: both.* A
    very common real need (badge counts, dashboards) with no answer today. The
    relationship VO already carries a meta member; add a `countRelated()` provider seam.
    Rides the same relation-meta builder as #11. (Merges two duplicate survey rows.)

11. **Testing-utility upgrade** (status+content-type-aware fetch assertions, exact-match
    document assertions, collection/ordered-collection assertions, a created-response
    bundle, a typed query DSL on the request builder). *Value: high (aggregate). Effort:
    M. Layer: both.* Our assertions only inspect the decoded **body** — HTTP status,
    `Content-Type`, and `Location` are never asserted as a unit, there is **no
    collection-level assertion** (so a `?sort` result has no first-class witness), and
    no exact-match (so stray fields leak invisibly). This is the test-DX backbone for
    everything else and should land alongside the features it tests.

**Suggested sequencing:** land the cheap high-value wins first (7 default-sort, 8
max-per-page, 9 the queued include bound) → then the hooks seam (#1, which unlocks
the most) → then authz (#3) + query-scoping (#5) together → then soft-delete (#2) and
countable (#10) as cohesive slices → with the testing upgrade (#11) threaded through so
each new feature ships with a first-class assertion.

---

## 2. Prioritised gap table (all real gaps)

Sorted by value (high → low) then effort (S → L). "Layer" is who owns the work.
Merged/duplicated rows are consolidated; the *deliberately-N/A* and *already-have*
rows are in §3 and §4.

| # | Feature | What Laravel does | Our state | Value | Effort | Layer | Recommendation |
|---|---------|-------------------|-----------|:---:|:---:|:---:|----------------|
| 1 | **Per-operation lifecycle hooks** (saving/saved/creating/created/updating/updated/deleting/deleted + per-relationship attaching/detaching/updating{Field}; after-hooks may return a Response) | Named before/after hooks per CRUD + relationship op; the primary author extension seam | missing | High | M | both | Optional per-type hooks interface invoked by `CrudOperationHandler` around create/update/delete **and** `mutateRelationship`; after-hooks may return a replacement response VO. Core defines the interface, bundle resolves by type via a tag. *The single biggest gap; unlocks #4, #12, authz, custom-action shaping.* (Merges 3 duplicate survey rows.) |
| 8 | **Default sort for a collection** (`$defaultSort` / `defaultSort()`) | Schema declares a default order applied when no `?sort` | missing | High | S | both | `defaultSort()` hook the provider's `CriteriaApplier` applies when `sort === []`. (Merges 2 duplicate rows.) |
| 9 | **Max-per-page cap** (`withMaxPerPage`) | Recommended `between:1,100` ceiling on page size | missing | High | S | core | Clamp resolved size `min(requested, max)` in `resolve()`/`window()` across all paginators. Protects every store; stays 200. |
| 6 | **Resource-object `self` link by convention** | Auto `self` link on every retrievable resource; `$selfLink=false` opts out | missing | High | M | both | Auto-emit `self` from `uriType`+id+baseUri with `withoutSelfLink()` opt-out (also drops relationship self/related links). |
| 4 | **Filter-value validation seam** | filter params validated → 400/422 before hitting the query | missing | High | M | both | Per-resource filter-param validation reusing the Symfony Validator bridge; core declares rule metadata, bundle executes. (Merges the "strictly-typed query-value rules" row.) |
| 5 | **Request/user-scoped collection filtering** (`indexQuery`/`relatableQuery`/global scopes) | Overridable query scoping with request/user access | missing | High | M | bundle | Thread `JsonApiRequest` (or a tagged per-type `QueryScope`) into `fetchOne`/`fetchCollection` (already in `fetchRelatedCollection`). |
| 9b | **Include-path safeguard** (`$maxDepth` + per-relation `cannotBeIncluded()`) | Caps nested-include depth + per-relation opt-out | partial (QUEUED) | High | M | both | **Ship the already-designed/queued bound**; preloader (ADR 0035) is built, the safeguard is not. Unknown paths already 400. |
| 3 | **Per-operation authorization seeing the loaded entity** (viewAny/view/create/update/delete + per-relationship) | Policy method per op, before validation; 403/401 | partial (firewall only) | High | L | bundle | Optional per-type authorizer in `CrudOperationHandler` after model resolution, throwing 403. *Reverses a deliberate non-goal — do it knowingly.* (Merges the per-relationship-authz row.) |
| 10 | **Countable to-many relations** (`canCount`, `?withCount`, `meta.count`) | COUNT pushed down without materialising the collection | missing | High | L | both | `canCount()` flag + `withCount` parse → relationship `meta.count`; `DataProvider::countRelated()` (Doctrine COUNT subquery / in-memory `count()`). Rides #11b relation-meta builder. (Merges 2 duplicate rows.) |
| 2 | **Soft-delete capability** (SoftDelete field; DELETE→soft, PATCH null→restore, force-delete, trashed-GET policy, WithTrashed/OnlyTrashed, relation `withTrashed()`, `asBoolean()` projection) | One declaration turns a resource soft-deletable with full lifecycle | missing | High | L | both | Ship as **one cohesive slice**: core SoftDelete field/marker + bundle wiring driving exclusion-scope, restore, force-delete, and the two trash filters. (Merges 10 survey rows; the Eloquent-event row is free via Doctrine.) |
| 11 | **Status+content-type-aware fetch assertions** | `assertFetchedOne` bundles 200 + content-type + body | partial (body only) | High | M | both | `assertStatus()`/`assertContentType()` seam on a response-backed document; bundle `JsonApiResponse` wrapper. |
| 11a | **Exact-match document assertions** (`assertFetchedOneExact`, `assertExactMeta/Links/Errors`) | Whole-member exact compare | missing | High | M | core | `assertResourceExact`, `assertExactMeta/Links`, `assertHasExactError` over a normalised member. Guards against leaked fields. |
| 11b | **Collection assertions** (`assertFetchedMany`/`InOrder`/`Exact`) | Whole-collection set/ordered assertions | missing | High | M | core | `assertCollectionCount/Contains/Order` — the **only** way to witness `?sort`. |
| 11c | **Created-resource assertion bundle** (201 + Location + id + body) | One call covers the create contract | missing | High | M | both | Core: assert Location/id on a PSR-7 response. Bundle: assert the HttpFoundation `Location` header. |
| 12 | **Detached-model lifecycle** (`deleteDetachedModel`/`deleteDetachedModels`) | Orphan removal at the relation on detach | missing | Med | M | both | `deleteDetached()` relation flag → Doctrine `orphanRemoval`; useful for owned aggregates. |
| 13 | **Per-relationship meta builder** (`meta()`/`withMeta()`/`serializeUsing`) | Declarative relationship-object meta | partial | Med | M | core | `AbstractRelation::meta(array|\Closure)` threaded into the rendered relationship object (the `AbstractRelation:547` "future link/meta wiring" stub). **Unblocks #10 and any relation-scoped meta.** (Merges 2 duplicate rows.) |
| 14 | **Custom (non-CRUD) actions** (`-actions/publish`, returning a `DataResponse`) | Extra endpoints rendering through the serializer with include/fields | missing | High | M | bundle | Action-registration helper / first-class pattern letting a bespoke Symfony controller build a `DataResponse` VO handed to the `ViewListener`. |
| 15 | **PATCH merge-before-validate** (`withExisting`) | Partial update validates against merged current+submitted values | partial | High | M | bundle | Feed merged hydrated-entity values into the document pass (or route cross-field/conditional rules through the post-hydration entity pass) so `CompareField`/`When` aren't silently skipped on a partial PATCH. (Merges 2 duplicate rows.) |
| 16 | **Delete-guard validation** (`deleteRules`/`metaForDelete`) | Validation that can veto a DELETE → 422/409 | missing | Med | M | bundle | Optional pre-delete guard seam on the handler so a delete can be refused cleanly (rides the hooks before-hook). (Merges 2 duplicate rows.) |
| 17 | **Conditional readOnly/hidden via closure** (`readOnly(fn)`/`hidden(fn)`) | Per-request writability/visibility | partial | Med | S | core | Closure overloads on `readOnly`/`hidden` for per-request, authz-driven field gating. |
| 18 | **DateTime `retainTimezone()`** | Preserve the wire offset explicitly | partial (default already retains) | Med | S | core | Mostly a **docs gap** — we already retain the wire offset; document it; add an explicit toggle only if app-default-timezone normalisation ever lands. |
| 19 | **Custom id route pattern** (`matchAs()`, ULID `ulid()`, route requirement) | `{id}` segment regex; router rejects malformed ids | missing/partial (QUEUED) | Med | M | both | **Already designed/queued.** Wire the Id field's format (uuid/numeric/pattern/ulid) into a per-route `setRequirement('id', …)` so malformed ids are a clean route miss. Add the trivial `ulid()` helper. (Merges 4 survey rows: matchAs, ulid, route-pattern, matchCase.) |
| 20 | **Pluggable id encode/decode** (`IdEncoder`, HashIds) | Wire id differs from storage key | missing (QUEUED) | Med | L | core | encode/decode hook on the Id field so provider/persister round-trip an opaque id. HashIds itself is userland. |
| 21 | **Eager-load the relation an attribute derives from** (`Attribute::on()` / `derivedFrom`) | Auto-eager-load a backing relation when serializing a derived attribute | missing | Med | M | bundle | Let an attribute declare a backing relation; Doctrine provider folds it into the always-preload set. Closes the N+1 `?include` doesn't cover. (Distinct from #22 — this is N+1 safety, not flattening.) |
| 22 | **Attribute flattened from a related model** (`Attribute::on(relation)`) | Read/write a column on a 1:1 related model as own attribute | missing | Med | L | both | **Defer past v1** but track. Core's own docs flag `Map::on()` as out-of-scope for 1.0; same gap. Needs a load-state/eager-load seam — size as a post-1.0 slice. |
| 23 | **Always-eager relationships** (`$with`) | Declarative per-resource always-load list | partial | Med | S | bundle | Possible today via a `DoctrineExtension` join (imperative); add a declarative per-resource always-load list folded into the base query. |
| 24 | **Sort by relationship count** (`SortCountable`/`countAs`/`using`) | Client sorts by a to-many count, pushed down as SQL COUNT | partial | Med | M | both | `SortByRelationshipCount` VO + Doctrine correlated-COUNT `ORDER BY` (reuse the `WhereHas` EXISTS machinery). |
| 25 | **`serving()` server hook** (request-scoped bootstrap) | Runs once per API request, per server | partial | Med | S | bundle | Per-server `onServing` seam fired by `RequestListener` after server resolution; and/or document the `kernel.request`-guarded recipe. |
| 26 | **Dynamic `baseUri()` callback** (multi-tenant host) | Compute base URI per request | partial (static only) | Med | S | both | Callable/resolver variant on `withBaseUri` (core) surfaced as a service-id config option (bundle). |
| 27 | **Disallow / require pagination** (force or forbid `page[]`) | Reject unpaginated, or 400 `page[]` on an unpaginated resource | partial (only "no pagination" end) | Med | M | both | `requirePagination()` / `page`-not-supported policy surfaced as 400 in the bundle request layer. |
| 28 | **Simple / no-total pagination** (`withSimplePagination`, `withoutMeta`) | Count-free page mode (prev/next + hasMore, skip COUNT) | partial (cursor only) | Med | M | core | `SimplePagePaginator` (or strategy flag): emit prev/next + hasMore, skip total/last/COUNT. `withoutMeta()` is a trivial toggle. |
| 29 | **Page meta key nesting + casing** (`withMetaKey`, `withoutNestedMeta`, snake/dash) | Author-controlled page-meta key + casing | missing | Med | M | core | Fluent meta-key + casing policy on the paginators so a snake_case API isn't forced to camelCase only inside page meta. Purely presentational. |
| 30 | **Opaque / encoded cursors** | Base64 keyset cursors; multi-field cursors | partial (raw id) | Med | M | both | Optional `CursorCodec` encode/decode hooks so cursors are opaque and multi-field — aligns with the cursor-pagination profile. |
| 31 | **Per-relationship extra filters** (`withFilters()`) | Filters scoped only to a relationship endpoint | partial | Med | M | both | `withFilters()`-equivalent on relation metadata adding/restricting filters distinct from the related resource's set. |
| 32 | **Pivot/join-table filters** (`WherePivot…`) | Filter a m2m collection by join-row columns | missing | Med | M | both | Doctrine join tables are usually entities (partly N/A), but add a `WhereJoin`-style VO + Doctrine arm if filtering association rows is needed. |
| 33 | **Scope filter** (delegate filter key to a named query fragment) | `filter[key]` → Eloquent local scope | missing | Med | M | both | `Scope`-style filter VO carrying a callable/repository-method name the Doctrine handler invokes on the QueryBuilder. |
| 34 | **BelongsToMany pivot fields via closure** (server-computed pivot values) | Closure receives parent+related to compute pivot columns | partial | Med | S | both | Widen `fields()` closure signature (and persister) to receive parent+related for per-row computed pivot values. |
| 35 | **Per-resource validator escape hatch** (`withValidator`/after-hook/`messages`/`attributes`) | Imperative ad-hoc rules + 422 message/attribute overrides | partial | Med | M | bundle | Optional `validateDocument()` resource hook the bridge calls after its passes; plus a `messages()`/`attributes()` override surface. (Merges 2 duplicate rows.) |
| 36 | **Per-request query-param narrowing** (`forget`/`forgetIf`/`forgetUnless`, `notSupported`) | Conditionally drop an allowed sort/filter per request; deny a param outright | partial/missing | Med | M | bundle | Per-resource/per-operation param allow/deny surface + conditional drop on `filters()`/`sorts()`. Pairs with #5 request-scoping. (Merges 3 duplicate rows.) |
| 37 | **Reject sparse fieldsets for unknown type/field** (`allowedFieldSets`) | 400 on `fields[unknownType]` or unknown field name | partial (silently ignored) | Med | M | both | Opt-in "reject unknown fieldset member/type" guard mirroring how `?include` 400s; keep silent-ignore as the lenient default. |
| 38 | **Extensible exception→JSON:API pipeline** (`ExceptionParser` append/prepend) | App registers custom exception→error mappers | partial (fixed 3-arm) | Med | M | bundle | Tagged exception-mapper extension point so an app maps its own domain/third-party exceptions without decorating the whole listener. |
| 39 | **Validation-error pointer customisation** (`withSourcePrefix`/`withPointers`) | Prefix/remap every error pointer | partial (fixed convention) | Med | M | bundle | Pointer-resolver seam so an app can remap violation paths (e.g. a VO property path ≠ wire member) to the correct JSON pointer. |
| 40 | **`identifierMeta()`** — meta on a resource *identifier* (in linkage) distinct from resource-object meta | Linkage meta differs from data meta | missing | Med | M | core | `getIdentifierMeta()` on `SerializerInterface` used in `transformResourceIdentifier()` instead of reusing `getMeta()`. |
| 41 | **Declarative conditional members** (`when()`/`mergeWhen()`) | Request-conditional attributes/meta, declaratively | partial (imperative only) | Med | M | core | Small `when()`/`mergeWhen()` helper on `AbstractSerializer`/a trait so conditional members read declaratively. |
| 42 | **Localisation of spec/exception error objects + locale negotiation** | Translatable title/detail/code, Accept-Language honoured | partial (validator 422 already localised) | Med | M | both | Bundle: inject `TranslatorInterface`, derive title/detail from translatable keys, negotiate locale. Core: built-in exceptions expose a message key, not a frozen string. (Validator 422 details are already localised.) |
| 43 | **Polymorphic to-many shared filter/sort vocabulary** | Declare a filter/sort set common to all member types | partial (renders; 400s filter/sort) | Med | M | both | Core: allow a declared shared filter/sort set on `MorphToMany` so common-key (e.g. `id`) filtering executes. Bundle: a per-member repository-map helper to cut polymorphic-provider boilerplate. |
| 44 | **Error-status assertion tying HTTP status to error object** (`assertErrorStatus`) | Asserts wire status + error body together | partial | Med | S | both | Let `JsonApiErrors` assert the response HTTP status (`assertResponseStatus`) — it already accepts the PSR-7 response. |
| 45 | **`assertHasExactError` / whole-array error match** | Exact + whole-array error assertions | partial (3 indexed fields) | Med | S | core | `assertHasExactError(array)` + `assertErrorsExact(list)` so error rendering is pinned beyond status/pointer/code. |
| 46 | **Typed test query DSL** (`includePaths`/`sparseFields`/`filter`/`sort`/`page`) | Fluent method per JSON:API query param | partial (generic `withQueryParam`) | Med | M | both | Typed query helpers on `JsonApiRequestBuilder` encoding the bracketed syntax; mirror on the bundle harness. Removes brittle hand-encoded query strings. |
| 47 | **`expects(type)` model-to-resource assertion binding** | Pass an entity, library derives the expected resource | missing | Med | L | bundle | Bundle-only `expectResource(object $entity)` running the entity through its serializer. Core can't (no model concept). |
| 48 | **Non-JSON:API / malformed request helpers** (`withRawBody`, override Content-Type) | Send a wrong/non-JSON:API body to test 415/406/400 | partial | Med | S | core | `withRawBody(string)` + let `withHeader` fully override the hardcoded Content-Type/Accept on the request builder. |
| 49 | **`actingAs()` / login in the request builder** | Test authz-scoped endpoints under an identity | partial | Med | M | bundle | Add `actingAs()`/`loginUser()` to `JsonApiFunctionalTestCase` (or document Symfony's `loginUser()`). Core N/A. Pairs with #3. |
| 50 | **`assertIsIncluded(type, model)` / `assertIncludedExactly`** | Assert a specific resource in `included`, or the exact set | partial (type+count only) | Med | S | core | `assertHasIncludedResource(type, id)` + `assertIncludedExactly(list)`. Witnesses the preloader by membership, not just count. |
| 51 | **hasOneThrough / hasManyThrough relation types** | Read-only through-projection relations | partial | Low | M | both | Expressible today via `extractUsing()`/`computed()` + a custom `fetchRelatedCollection()`. Add a first-class Doctrine through-mapping only on demand. |
| 52 | **Number `acceptStrings()` / strict mode** | Opt-in numeric-string strictness | partial (always coerces) | Low | S | core | Optional `strict()` emitting a type constraint so authors who want wire-type rigor get a 422 instead of silent coercion. |
| 53 | **Array key-case transforms** (`camelizeKeys`/`dasherizeKeys`/…) | Transform object-key case between wire and storage | missing | Low | S | core | Trivially addable serialize/deserialize key-mapper on `ArrayHash`; `serializeUsing`/`deserializeUsing` cover it ad hoc today. Skip for v1. |
| 54 | **Map `ignoreNull()`** | Skip present-null on a Map child during hydration | partial | Low | S | core | We skip *absent* keys (correct partial-update) and readOnly children, but present-null writes null. `deserializeUsing` covers it; niche. |
| 55 | **Multi-paginator** (client chooses page OR cursor) | One resource advertises both strategies | missing | Low | M | core | Composite `MultiPaginator` dispatching by which `page[]` keys are present. Build only on demand. |
| 56 | **Fluent operator setters on `Where`** (`gt`/`gte`/`lt`/`lte`/`eq`/`using`) | Readable operator methods after `make()` | partial (3rd ctor arg) | Low | S | core | Ergonomics-only helpers; functionally equivalent today. |
| 57 | **`deserializeUsing` on set filters** (`WhereIn`/`WhereNotIn`) | Per-element normalisation on set filters | partial (only `Where`) | Low | S | core | Add per-element `deserializeUsing` to `WhereIn`/`WhereNotIn`. Low priority. |
| 58 | **`Error::fromArray()` / fluent setters** | Build errors from an array or fluently | partial (named-arg ctor) | Low | S | core | `fromArray()` factory eases building errors from config/external sources. Named-arg construction covers the common path. |
| 59 | **`is4xx()`/`is5xx()` helpers** | Branch on the aggregate status class | missing | Low | S | core | Expose on `JsonApiExceptionInterface`; status-class derivation already lives in `ErrorResponse`. |
| 60 | **`withoutHeaders()` response wither** | Remove previously-set headers | missing | Low | S | core | Trivial symmetry with `withHeader(s)`; rarely needed. |
| 61 | **`report()` log-control hook** | Per-exception suppress/defer logging | partial | Low | S | bundle | Small reportable hook + channel/level config. Default (log unexpected, skip expected) is sensible. |
| 62 | **`id()`/`type()` accessors on test document** | Extract new id for create-then-fetch chaining | partial | Low | S | core | `id()`/`type()` on `JsonApiDocument` returning primary resource id/type. Cheap DX. |
| 63 | **Meta-only / absence assertions** (`assertNoData`/`assertNoMeta`/`assertNoLink`) | Assert data absent, meta/links absent | partial | Low | S | core | Trivial; useful for meta-only endpoints and asserting `withoutLinks()` suppression. |
| 64 | **Pretty-printed key-sorted JSON diffs on failure** | Human-readable assertion diffs | missing | Low | S | core | Largely free once exact-match (#11a) lands; recursively `ksort` both sides for stable diffs. |
| 65 | **`asBoolean()` soft-delete projection** | Bool wire field over a datetime column | missing | Low | S | both | Folds into the soft-delete slice (#2); a boolean-projection option on the SoftDelete field. |
| 66 | **`alwaysShowData()` (force linkage in every compound doc)** | Force the `data` member even when not loaded | have-ish | Low | S | core | We have `dataOnlyWhenLoaded()` (the `showDataIfLoaded` twin) and otherwise emit data; the "always force" nuance is a minor gap. Note only. |
| 67 | **`page[]` key validation against the paginator** (`Rule::page`) | 400 an unknown paging key | missing | Low | S | both | By design we clamp garbage `page[]` and stay 200. Optional strict mode that 400s an unrecognised key. Low priority. |
| 68 | **Auto-derived resource type from class name** | `PostSchema` → `posts` unless `type()` overrides | partial (explicit `$type` required) | Low | S | both | Honest tradeoff, not a defect. A derive-when-empty convenience would cut boilerplate; low value since `$type` doubles as the registry key. |
| 69 | **Per-member-type config in one polymorphic relation** (distinct pivot/filters/include per sub-type) | Each `MorphToMany` sub-relationship fully configured | partial (flat type list) | Low | M | core | Sufficient for read-render today; revisit only if a consumer needs heterogeneous pivots. Custom-provider escape hatch covers execution. |
| 70 | **Custom route names per action** (`->name`/`->names`) | Override generated route names | partial | Low | S | bundle | Our scheme `jsonapi.{server?}.{type}.{action}` is predictable/collision-free; add an override only on a reported clash. |
| 71 | **Custom 403 denial message** (`Response::deny('…')`) | Per-case prod-safe 403 detail | partial | Low | S | bundle | If the authz seam (#3) lands, let a denial carry an explicit, prod-safe detail distinct from the debug-gated exception message. |
| 72 | **Override one action by trait removal** | Surgically replace one action's behaviour | partial | Low/Med | M | bundle | Largely covered by the hooks seam (#1); if finer control is wanted, a per-type `OperationHandlerInterface` override resolved before the global handler. |
| 73 | **Per-action middleware/security in the route definition** | `->middleware('can:admin')` per action | partial (import granularity) | Med | M | bundle | Optional per-operation security/condition expression on `#[AsJsonApiResource]`/the route descriptor stamped onto the matching Route; or document the named-route + `access_control` recipe. Pairs with #3. |

---

## 3. Deliberately not / N/A

Framework- or Eloquent-specific features with no honest analogue in a
storage-agnostic core + Doctrine bundle, **or** behaviours we consciously omit.
No action recommended.

- **Eloquent mass-assignment escape (`unguarded()`)** — entities have no `$fillable`;
  our write-gating is `readOnly*()`/`hidden()` at the field layer, the correct equivalent.
- **Eloquent model events / observers on save/delete/restore** — Doctrine lifecycle
  callbacks / event subscribers fire on the persister's `flush()` for free. *Document
  the equivalence in `doctrine.md`.*
- **`serving()` route-model-binding integration / `SubstituteBindings` opt-out** —
  Eloquent route-model-binding by parameter name. Our analogue is `DataProvider`
  fetch-by-type+id with provider-side scoping.
- **Custom route parameter name (`->parameter('post')`)** — Laravel route-model-binding
  by parameter name; our routes use fixed `{id}`/`{relationship}`.
- **`withServer()` per-response server selection** — we resolve the server earlier (a
  `_jsonapi_server` route default); same capability, different (deliberate) model.
- **`withoutRelationshipMeta()`** — undoes a Laravel default (auto-merging relationship
  meta into top-level) we never had.
- **`retainFieldName()`** — we don't auto-dasherize URI segments, so there's nothing to retain.
- **`notSortable()` on an auto-sortable id** — our model is opt-in `sortable()`; ids aren't
  auto-sortable, so there's nothing to opt out of (a safer default).
- **`WithTrashed`/`OnlyTrashed` as gedmo extensions** — *as standalone filters* they're
  N/A; **but** they're in-scope **as part of the soft-delete slice (#2)**, cooperating
  with a first-class soft-delete scope.
- **Boolean-softdeletes companion package** — covered by `asBoolean()` over a real
  boolean column inside our SoftDelete field (#65); no separate package.
- **Soft-delete lifecycle events** — Doctrine/Symfony events already cover this.
- **Cursor column/direction configuration** — Laravel's cursor paginator owns query
  construction; ours is store-agnostic (the caller supplies boundary cursors). A
  Doctrine keyset helper could ship later, but the strategy itself is correctly agnostic.
- **`$model` resolution to parent classes/interfaces** — we select the serializer by
  JSON:API type (registry key), not reverse object→schema lookup; polymorphism is handled
  by `PolymorphicSerializer` + per-object `resolveSerializer`. The Doctrine-inheritance
  analogue would live in the entity-map. Note only.
- **Proxy resource types** — our capability-composed type model already does this more
  cleanly (one entity → multiple types via standalone serializers with distinct `uriType`).
  *Ship a "one entity, two resource types" docs recipe — no new machinery.*
- **Non-Eloquent resource toolkit** — this **is** our default Provider/Persister SPI, not
  an add-on. `custom-data-providers.md` ships the worked witnesses. *Call out the parity
  story in Phase 5 docs.*
- **Artisan generators** (`jsonapi:request`/`query`/`authorizer`) — our model is
  attribute/constraint-on-field discovered by autoconfiguration; no per-request classes to
  scaffold. A maker-bundle generator is a possible later nicety, not a gap.
- **Separate query classes per cardinality + allowedCountableFields structure** —
  framework-specific scaffolding; our single resource declares `filters()`/`sorts()`/
  `pagination()`. (The countable-fields *capability* is the real gap → #10.)
- **Model factories / `assertDatabaseHas` in the test layer** — Foundry/DoctrineFixtures
  already cover this; *document the integration* rather than reinvent it.

**A note on authorization as a non-goal.** `security.md` deliberately delegates authz to
the Symfony firewall + `access_control` + voters. That stance holds for *path/route-level*
gating (401-vs-403 split, guest handling, per-route security — all genuinely covered). The
**one thing the firewall cannot do** is see the *loaded entity* for a per-operation check
("can this user update THIS album"). Closing #3/#73 is a *conscious, bounded* extension of
the non-goal, not an abandonment of it.

---

## 4. Already covered, or already queued

No new work — listed to confirm parity and avoid re-scoping.

**Already covered (parity or better):**

- **Singular-filter collapse** — *(corrects the raw survey)* `CrudOperationHandler`
  collapses an applied singular filter to a zero-to-one response on both providers (ADR 0033).
- **Restrict resource/relationship actions** (`only`/`except`/`readOnly`) — covered by the
  `Operation` allow-list on `#[AsJsonApiResource]`/`#[AsJsonApiSerializer]` and per-relation
  exposure flags. *(Minor nicety: a `readOnly()`/`withoutWrites()` shorthand.)*
- **Server-level routing group config** (prefix/host/condition/middleware/name) — native
  Symfony route-import config; multi-server done. Namespace is N/A (one global controller).
- **Schema-level eager loading / N+1 prevention for `?include`** — the optional shipmonk
  preloader (ADR 0035) batch-loads included paths.
- **Default include paths when none sent** — `getDefaultIncludedRelationships()` (rendered
  **and** batch-preloaded).
- **Sort by an arbitrary DB column** — `SortByField::make($key, $column)`.
- **Default value for an attribute** — hydrate writes only filled members; the domain object
  owns defaults.
- **`fillUsing` receives all validated data** — our closure gets `$data` (whole attributes payload).
- **`asBoolean()` truthy vocabulary** — `FILTER_VALIDATE_BOOLEAN` (1/true/on/yes).
- **Undeclared filter/sort keys reject with 400** — `FilterParamUnrecognized`/
  `SortParamUnrecognized`/`SortingUnsupported` (stricter than core's documented silent-ignore).
- **Application-level write validation rules per resource** — field constraints executed by
  the Symfony Validator bridge → 422 with `source.pointer` (messages are translator-driven).
- **Client-generated ID validation** — Id field `pattern()` → Symfony Regex + the
  client-id exceptions.
- **Content negotiation + structural compliance** — `RequestValidator` does the asymmetric
  Accept(406)/Content-Type(415) rule, ext negotiation, query-family + top-level-member
  checks; optional opis linter adds full JSON-Schema validation. (Parity and stricter.)
- **Validator 422 detail localisation** — already free via Symfony's `validators` domain.
  (The *spec/exception* localisation half is the open part → #42.)
- **Default server meta + JSON:API version** — `withVersion` + `withDefaultMeta`.
- **`selfUrl()`/`selfMeta()`** — `getLinks()` returns `ResourceLinks` with a custom self
  `Link` carrying meta. *(The convention-based auto self link is still the gap → #6.)*
- **Full JSON:API 1.1 link-object vocabulary** — `LinkObject` supports rel/title/type/
  hreflang/describedby/meta with auto bare-string-vs-object form. (Exceeds Laravel.)
- **Polymorphic to-many rendering** — `PolymorphicSerializer` + per-object `resolveSerializer`;
  in-memory supported, Doctrine throws → custom provider; correctly 400s filter/sort, pages.
  *(Only the shared-vocabulary configurability is open → #43.)*
- **Guest 401-vs-403 split, content negotiation rejection** — Symfony firewall + the
  route-scoped `ExceptionListener` render both as JSON:API error documents.

**Already designed and QUEUED (ship the existing design, don't re-scope):**

- **Include-path safeguard** — `$maxDepth` default + per-relation `cannotBeIncluded()` (→ #9b).
- **Custom id route pattern + `ulid()` + custom id encoding** — `matchAs` route requirement,
  `ulid()` helper, opaque id encode/decode (→ #19, #20).
