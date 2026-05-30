# Phase 2 — Profiles Infrastructure & Pagination

## Goal & scope

Introduce a general-purpose JSON:API profile infrastructure into the core, and refactor the built-in paginators to be its first consumers. By the end of this phase the package can advertise, negotiate, and apply profiles correctly per the JSON:API 1.1 specification, and consumer code can register custom profiles without library changes.

**In scope:**

- Profile abstraction: a contract that any profile (built-in or consumer-supplied) implements
- Profile registry: how profiles are registered, looked up, and discovered
- Content-negotiation integration: parsing and emitting the `profile` media-type parameter; advertising supported profiles
- Document-level profile linkage: emitting `links.profile` when profiles are in effect
- **Pagination rewrite:** split into `Paginator` (request-parser + strategy config) and `Page` (per-strategy value object carrying paginated items + link-emission metadata). Replaces yin's `PaginationLinkProviderInterface` + collection-side trait pattern (not ported). Paginators integrate with the profile infrastructure where a published profile URI exists.
- Tests tagged with relevant spec sections (notably `spec:extensions-and-profiles` and `spec:content-negotiation`)

**Out of scope:**

- Atomic Operations extension (post-1.0 candidate; it's an `ext`, not a `profile`, but uses the same negotiation layer — design the negotiation parsing to handle both)
- Schema validation of profile-defined keywords (Phase 4)
- PSR-15 middleware that integrates profiles with the request lifecycle (Phase 3)
- Attribute-driven profile declarations on resources (post-1.0 candidate)
- Authoring or publishing custom profiles for `haddowg` — only the infrastructure

## Prerequisites

- Phase 1 complete: core ported, modernised, exception hierarchy in place
- Pagination implementations exist and work (ported in Phase 1)
- Content-negotiation logic exists (ported in Phase 1) and is the integration point for profile handling

## Kick-off

Before writing any implementation code:

1. Read `docs/phase-1-core-port.md` — specifically its decision log and handover output — and reconcile against the current repository state.
2. Re-read the [JSON:API 1.1 Extensions and Profiles section](https://jsonapi.org/format/1.1/#extensions) end-to-end. The plan below is grounded in that section; if any divergence is found, fix the plan first.
3. Re-read this plan in full. Resolve every open question (and any new ones surfaced during the read) by asking the maintainer interactively using whatever ask-user-question tool the executor's environment provides (e.g. `AskUserQuestion` in Claude Code). Do not guess or silently defer. Record each answer in the decision log.
4. Survey the JSON:API ecosystem for actually-published profile URIs (e.g. cursor-pagination, atomic-operations is separate as it's an `ext`). Record the inventory in the decision log so it can inform paginator wiring.
5. Revise the task list as needed and commit the plan revision as a single commit before starting implementation.

## Task list

### Profile abstraction

- [ ] Define `haddowg\JsonApi\Schema\Profile\ProfileInterface` with at minimum:
  - `getUri(): string` — the profile's canonical URI
  - A mechanism to declare any reserved keywords the profile contributes (e.g. attribute names, link relations, query parameters) — exact shape decided during kick-off
  - Optional lifecycle hook(s) for document-finalisation if needed (decide whether profiles can mutate the outgoing document; spec allows them to define semantics but not arbitrarily mutate, so this hook may be unnecessary)
- [ ] Define base/abstract class if useful to reduce boilerplate for profile authors
- [ ] Add value object(s) for parsed profile media-type parameters

### Profile registry

- [ ] `haddowg\JsonApi\Schema\Profile\ProfileRegistry` — register, look up by URI, list all
- [ ] Decide registry scope: per-`JsonApi` instance (i.e. wired at orchestrator construction) vs. global static. Recommendation: per-instance, injected; avoids global state.
- [ ] Decide eager vs. lazy resolution; profiles are cheap objects so eager is likely fine
- [ ] **Forward-compatibility note.** Phase 4.5 introduces a broader resource-type `Registry` that holds schemas, profiles, and optional resource/hydrator overrides. The profile registry shipped here is likely to fold into that broader registry. Design the public API of this registry with that future merge in mind: avoid naming choices or signatures that would force a breaking rename later. Record the chosen naming in the decision log.
- [ ] Unit tests for registration, conflict handling (same URI registered twice), and lookup

### Content-negotiation integration

- [ ] Extend negotiation parsing to read the `profile` parameter on `Content-Type` and `Accept` headers per JSON:API 1.1 rules:
  - `profile` is a space-separated list of URIs
  - Client requests profiles via `Accept` header `profile` parameter
  - Server applies profiles via `Content-Type` response header `profile` parameter
- [ ] Generalise the negotiation parser to also handle `ext` (so that a future Atomic Operations implementation can drop in without re-working negotiation); structure the parsing so adding `ext` is trivial later
- [ ] Reject requests that demand unsupported profiles per spec (`406 Not Acceptable`) using a typed exception
- [ ] Apply (echo back) profiles on response `Content-Type` when the request advertised them and the server supports them
- [ ] Tests covering: requesting supported profile, requesting unsupported profile, no profile, multiple profiles, malformed `profile` parameter

### Document-level integration

- [ ] When a profile is applied to a response, the document's top-level `links.profile` member should reflect the profile URI(s) per the spec. Add the wiring so a document picks this up from the response context without each document class needing to know.
- [ ] Tests asserting `links.profile` emission

### Pagination refactor — `Paginator` + `Page` split

This is a structural rewrite of yin's pagination, not a modernisation. The intent and request-parsing logic stay; the link-emission machinery is replaced.

- [ ] Yin's `PaginationLinkProviderInterface`, `PageBasedPaginationLinkProviderTrait`, `OffsetBasedPaginationLinkProviderTrait`, `CursorBasedPaginationLinkProviderTrait`, and `FixedPageBasedPaginationLinkProviderTrait` are **not ported**. The collection-side trait pattern is replaced wholesale. Record this in the decision log with a one-line rationale.
- [ ] `DocumentLinks::setPagination($uri, $linkProvider)` is **not ported** in its current form. Pagination link generation moves to the `Page` value objects below.
- [ ] **`Paginator` (renamed from yin's `Pagination`)**. Strategy classes that read `page[...]` query params and hold defaults. One per pagination strategy: `PagePaginator`, `OffsetPaginator`, `CursorPaginator`, `FixedPagePaginator`. Fluent: `make()` static factory plus `withPageKey('number')`, `withPerPageKey('size')`, `withDefaultPerPage(15)`, etc. (vocabulary borrowed from Laravel JSON:API; finalise during implementation).
- [ ] **Naming check**: yin uses "Pagination" for both the strategy and the parser. Renaming to `Paginator` matches the noun-for-the-thing pattern used elsewhere (`Schema`, `Field`, `Filter`). Lock in or revert at kick-off; document the choice.
- [ ] **`Page` value objects.** Strategy-specific, immutable, carry the paginated items + the metadata the strategy needs to emit links and meta:
  - `PageBasedPage` — totalItems, currentPage, size; emits `first`/`prev`/`next`/`last` based on `ceil(totalItems / size)`.
  - `OffsetBasedPage` — totalItems, offset, limit; emits `first`/`prev`/`next`/`last`.
  - `CursorBasedPage` — limit, cursorBefore, cursorAfter, hasMore (no totalItems by design); emits `first`/`prev`/`next` only — **`last` is intentionally omitted** because computing it would defeat the cursor's purpose. Document this explicitly.
  - `FixedPagePage` — currentPage, totalItems (or hasMore for simple pagination); same emission shape.
- [ ] `Page` is iterable (implements `IteratorAggregate`) so the response-side iteration over the paginated items "just works" without unwrapping.
- [ ] `Page` exposes a link-emission method (working signature: `linkSet(string $baseUri, array $queryParams): array<string, Link|null>` returning the `first`/`prev`/`next`/`last` map) and a meta-emission method (`pageMeta(): array` returning the contents of `meta.page.{...}`). Both invoked by `DataResponse` rendering (the rendering signatures stabilise during implementation).
- [ ] `Paginator::paginate($queryResult, ...): Page` produces the right `Page` subtype from a query result plus the request's pagination params. The contract is small; consumers/adapters can implement custom paginators by implementing `Paginator` and returning whatever `Page` subtype is appropriate.
- [ ] **No collection-side concerns.** Collections passed to `DataResponse::make($collection)` are plain iterables — `array`, `Doctrine\Common\Collections\Collection`, generators — and never implement a pagination interface or use a pagination trait. The paginated path is `DataResponse::make($page)` where `$page` is a `Page` instance. Coordinate with the Phase 1 `DataResponse` design.

### Pagination profile association

- [ ] Audit each `Paginator` for an associated published profile URI:
  - Cursor pagination has a community-recognised profile — confirm during kick-off survey
  - Page-based and offset-based may not have published profiles; if not, declare no profile association and document that decision
- [ ] Where a profile URI exists, the paginator should:
  - Declare its profile via a method on the paginator or by implementing a `ProfileAwarePaginator` sub-interface (decide during implementation)
  - Cause the response to advertise the profile via the negotiation layer when used. The `Page` value object carries the profile URI through to rendering.
- [ ] Update existing paginator tests to assert profile behaviour where applicable; add new tests for the profile-aware path; add tests for the fluent builders; add tests for each `Page` subtype's link emission against fixtures (including cursor's omission of `last`).

### Public API & ergonomics review

- [ ] Walk the additions made in this phase; confirm naming is consistent with Phase 1 conventions
- [ ] Confirm a consumer can register a custom profile in a small amount of code; if it feels awkward, fix it before phase close
- [ ] Update `docs/spec-compliance.md` rows for extensions/profiles and content negotiation

## Decision log

_(Appended to during execution.)_

| Date | Decision | Rationale | Affects |
|---|---|---|---|
| _yyyy-mm-dd_ | _(example: profile registry is per-`JsonApi` instance, not global)_ | _(rationale)_ | _(this phase / future phases)_ |

## Open questions

- Should profiles be allowed to mutate outgoing documents (e.g. add top-level members) or strictly declarative metadata? The spec leaves room either way; lean strict-declarative to keep the contract small, with explicit hooks added only when a future profile needs them.
- Should the registry be a simple map, or support content-type-style negotiation (e.g. quality factors)? Spec doesn't require negotiation across profiles; simple map is likely sufficient.
- Sub-interface (`ProfileAwarePaginator`) vs. method-on-base (`getProfile(): ?ProfileInterface`)? Sub-interface is purer but adds an `instanceof` check at the negotiation site. Decide during implementation.
- How are profile-defined query parameters surfaced to user code? Out of scope for this phase if no built-in profile defines one, but flag the design space in the decision log.
- Should `ext` parsing be implemented in this phase (just the parsing layer, not the dispatch) so a future Atomic Operations effort can drop in cleanly? Recommendation: yes, parse both `profile` and `ext`, even though only `profile` is wired through.

## Acceptance criteria

The phase is done when all of the following hold:

1. All task-list items are checked off.
2. The profile abstraction exists with at least one consumer (a paginator) that registers and applies a profile end-to-end.
3. The negotiation layer parses both `profile` and `ext` media-type parameters (even if `ext` is not yet wired anywhere downstream).
4. Unsupported-profile requests produce the spec-mandated `406` response via a typed exception.
5. Supported-profile responses carry the profile URI(s) in their `Content-Type` and in `links.profile`.
6. PHPStan level 9 passes; CI matrix green; spec-tagged tests pass.
7. `docs/spec-compliance.md` updated for extensions/profiles and content negotiation rows.
8. `CLAUDE.md` updated with pattern entries for the new component kinds introduced this phase (profile interface implementations, profile-aware paginators, `Page` value objects) and any refinements to existing patterns.
9. **Pagination rewrite is complete and observable end-to-end.** `DataResponse::make($page)` (where `$page` is a `Page` subclass produced by a `Paginator`) renders a JSON:API document with correct `links.{first,prev,next,last}` and `meta.page.{...}` for the page-based and offset-based strategies; the cursor-based strategy produces `first`/`prev`/`next` and **does not** emit `last`. Yin's `PaginationLinkProviderInterface` is absent from the codebase.

### Verification plan

```bash
composer install
composer test
composer phpstan
composer cs-check

# Profile-specific spec coverage
vendor/bin/phpunit --group spec:extensions-and-profiles
vendor/bin/phpunit --group spec:content-negotiation
vendor/bin/phpunit --group spec:pagination

# Lowest-deps run
composer update --prefer-lowest --prefer-stable
composer test
```

Manual review:

- Write a throwaway custom profile in a sandbox, register it, hit a request demanding it, confirm: (a) the response advertises it, (b) `links.profile` carries it, (c) demanding a profile not registered returns `406`.
- Confirm the `ext` parameter is parsed and exposed on the negotiated request representation, even though no `ext` is yet supported — this is the hook a future Atomic Operations implementation will plug into.

## Handover output

Before declaring the phase complete, produce the following for Phase 3:

1. **Status table update** in `docs/PLAN.md` — Phase 2 → `Complete`, Phase 3 → `Ready`.
2. **Phase 3 plan review** — `docs/phase-3-middleware.md` already exists as a pre-drafted plan. Read it end-to-end against this phase's decision log and current repository state. Confirm it still covers at minimum:
   - PSR-15 middleware suite to ship: content negotiation middleware, request body parsing middleware, error handling middleware (catches `JsonApiException` instances → error documents), and a reserved-but-unimplemented slot for atomic-ops middleware (atomic operations is a post-1.0 candidate)
   - Middleware order and composition guidance
   - How middleware integrates with the orchestrator (`JsonApi` class) and the profile registry from this phase
   - PSR-7 request/response factory wiring for response creation inside middleware
   - Test plan for each middleware
   - Append revisions to the plan as a single commit; the actual kick-off revision happens at the start of Phase 3, but corrections forced by Phase 2 decisions belong here.
3. **Profile infrastructure documentation stub** — a placeholder under `docs/` to be filled out in the docs phase (just a heading + a paragraph linking to spec for now is fine).
4. **Open questions resolved** — every entry in the Open questions section above has an answer recorded in the decision log. Resolve any remaining or newly-surfaced questions by asking the maintainer interactively using whatever ask-user-question tool the executor's environment provides. Open questions are not passed forward to Phase 3.
5. **Decision log finalised** — phase-local decisions captured here; any cross-phase decisions promoted to `PLAN.md`.
