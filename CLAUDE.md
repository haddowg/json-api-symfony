# CLAUDE.md — executor playbook (json-api-symfony)

Maintenance playbook for `haddowg/json-api-symfony`, read by future Claude Code
sessions (including after compaction or restart). It records the decisions behind
this bundle and the conventions it inherits.

## Project orientation

`haddowg/json-api-symfony` is a **Symfony bundle** that makes
[`haddowg/json-api`](https://github.com/haddowg/json-api) idiomatic in a Symfony
application. The core library is **framework- and storage-agnostic**; this bundle
supplies the framework integration (DI, routing, the request lifecycle) and a
**reference Doctrine ORM data layer**.

Core is developed as a sibling checkout at `../json-api`
(`/Users/gregory.haddow/Sites/json-api`); its own `CLAUDE.md` is the authority on the
library's internals. When the bundle needs a core change, make it in core first (PR +
ADR + tests), get it green and released there, then consume it here. Prefer fixing core
over working around it in the bundle, weighed against the compatibility cost.

## Architecture decisions (the design this bundle implements)

- **DI / discovery.** Resources are Symfony services, discovered by
  autoconfiguration (any `AbstractResource`) plus an optional
  `#[AsJsonApiResource]` attribute for metadata (server assignment, overrides).
  Core's `ResourceRegistry` gains a **lazy container resolver** (reads `::$type`
  statically, constructs via an injected resolver on first use) so registered
  services can have real constructor dependencies.
- **Routing.** A route loader auto-registers the standard JSON:API endpoint set
  per resource; a `Target` resolver builds the `Operation\Target` from route
  defaults. Explicit-route users use the resolver primitive directly. Router-native
  (no catch-all path parsing).
- **Lifecycle = kernel listeners (NOT core's PSR-15 middleware).**
  `kernel.request` negotiates + parses + resolves the operation and calls
  **`Server::dispatch()`** (core's PSR-15-bypassing entry point); `kernel.view`
  renders the response value object to HttpFoundation; `kernel.exception` renders
  errors. This requires core's lifecycle *logic* (negotiation, validation,
  rendering) be drivable **without instantiating any `Middleware\*` class**.
- **Errors.** The `kernel.exception` listener is **route-scoped** (acts only when
  the matched route carries the bundle's marker) and **owns all errors** on
  JSON:API routes: core exceptions natively, `HttpExceptionInterface` mapped by
  status (firewall 401/403, routing 404), everything else 500. Debug meta gated on
  `kernel.debug`; unexpected errors logged via Symfony's logger.
- **Multi-server / versioning.** Architecture is **N-server-capable, single-server
  optimized**: one API needs no `servers:` block (implicit `default` server);
  server selection is a `_jsonapi_server` route default.
- **Validation.** First-class **Symfony Validator bridge**: translate core
  `ConstraintInterface` VOs → Symfony `Constraint`s, validate on create/update in
  the hydration path, map violations → JSON:API `422` with `source.pointer`.
  `When` and the date bounds (`After`/`Before`/`Between`) via `Callback`; a custom
  constraint VO (attached with core's `constrain()`) via a class-keyed
  `ConstraintTranslatorInterface` extension point. Core's
  opis JSON-Schema validation is wired as an optional dev/CI toggle.
- **Data layer = Provider/Persister SPI, Doctrine reference impl.** The generic
  CRUD handler is storage-agnostic over a bundle `DataProvider` (fetch one /
  collection) + `DataPersister` (create / update / delete / relationship-mutate),
  resolved per type. Doctrine implements them, composing core's
  `FilterHandlerInterface` / `SortHandlerInterface`. An **in-memory provider** is
  kept as a test double + conformance witness so findings stay attributable.
- **Handler model.** Per-type operation-handler dispatch is the foundation; the
  **generic Doctrine CRUD handler is the capstone**, built as a *refactor* of the
  proven per-type handlers over the SPI (not a speculative greenfield build).
- **Capability composition.** A JSON:API type is assembled from **independent,
  optional capabilities** — serializer, hydrator, relations, provider, persister —
  any combination supported, **nothing coupled to `AbstractResource`** (which stays
  pure sugar bundling serializer + hydrator + relations): need no read endpoints and
  you need no provider; no writes, no hydrator/persister. A per-type operation
  allow-list gates which CRUD endpoints exist and the route loader emits only the
  allowed routes.

Record bundle architecture decisions as ADRs under `docs/adr/` — follow
[`docs/adr/ADR-FORMAT.md`](docs/adr/ADR-FORMAT.md) (a short title stating the
decision, then 1–3 sentences of *why*).

## Tooling & conventions (inherited from core)

```bash
composer test       # PHPUnit (attributes only, no annotations)
composer phpstan    # PHPStan level 9
composer cs-check   # PHP-CS-Fixer, PER-CS 2.0
```

- **Namespace** `haddowg\JsonApiBundle\` (matches core's lowercase `haddowg\`
  vendor segment). Bundle class: `haddowg\JsonApiBundle\JsonApiBundle` (an
  `AbstractBundle`).
- **Conventional Commits** for every commit and **PR title** (PRs squash-merged;
  release-please drives versioning). Mark a breaking change with `!` or a
  `BREAKING CHANGE:` footer. PR descriptions read as external-contributor prose — no
  "What/Why" headings, no reference to internal planning. Follow
  `~/.claude/references/commits.md` and `~/.claude/references/pull-requests.md`.
  Rebase with `--force-with-lease`.
- **CS disables `global_namespace_import`** (same as core): reference global
  classes/functions inline (`\Exception`, `\dirname`), never imported.
- **PHPStan level 9.** Consider adding `phpstan/phpstan-symfony` if container/magic
  false positives appear; not installed in the scaffold.
- **CI** mirrors core (PHP 8.3/8.4/8.5 × lowest/highest).
- Existing tests are the contract — seed correct data to satisfy them, never edit a
  test to pass. Functional slices run identical conformance assertions against
  **both** the Doctrine (sqlite) and in-memory kernels, so a finding stays
  attributable to the data layer rather than the wiring.
