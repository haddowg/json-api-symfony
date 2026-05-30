# Phase 6 — 1.0 Readiness Review

## Goal & scope

Final review pass before cutting `1.0.0`. By the end of this phase the package is tagged `1.0.0` with full semver commitment, every shipped feature is documented and tested, the public API has been deliberately reviewed for naming and consistency, and a baseline for performance and security exists for future comparison.

**In scope:**

- Spec compliance audit (every MUST/SHOULD against the JSON:API 1.1 spec, tracked in `docs/spec-compliance.md`)
- Public API surface review — naming, consistency, dead code, type soundness
- Performance baseline — minimal benchmark suite recorded for regression detection
- Security review — focused on the inputs the library accepts and the outputs it produces
- CHANGELOG curated for the 1.0 entry
- Release-please tagging or manual tagging of `1.0.0`
- Post-release plan: scheduling of post-1.0 candidates

**Out of scope:**

- Adding new features (any feature gap surfaced during the review is either filed for a future minor or deferred to a post-1.0 phase)
- Building the post-1.0 features themselves (Atomic Operations, attributes)
- Building a documentation site

## Prerequisites

- Phases 1–5 complete (including Phase 4.5)
- `docs/spec-compliance.md` up to date
- CI consistently green across the full matrix

## Kick-off

Before starting the review:

1. Read `docs/phase-5-docs.md` — specifically its decision log and handover output — and reconcile against the current repository state.
2. Confirm the maintainer (the user) is ready to commit to semver — once 1.0 is tagged, breaking changes require a major bump.
3. Re-read this plan in full. Resolve every open question (and any new ones surfaced during the read) by asking the maintainer interactively using whatever ask-user-question tool the executor's environment provides (e.g. `AskUserQuestion` in Claude Code). Do not guess or silently defer. Record each answer in the decision log.
4. Revise the task list as needed and commit the plan revision as a single commit before starting review work.

## Task list

### Spec compliance audit

- [ ] Walk `docs/spec-compliance.md` row by row. For each row, confirm the status is one of:
  - `covered-by-test` — at least one test asserts the requirement
  - `covered-by-code-only` — implemented but not asserted; **file an issue** to add a test (decide whether to block 1.0 on each individually)
  - `not-covered` — **must be addressed before 1.0** or explicitly documented as a known gap with a follow-up issue
  - `intentionally-unsupported` — recorded with rationale; only legitimate if the spec allows the omission
- [ ] For any `not-covered` or `covered-by-code-only` rows that block 1.0, schedule the fix in this phase
- [ ] Cross-check the file against the spec itself — confirm no MUSTs were missed during Phases 1–4.5

### Public API surface review

- [ ] Walk every public class, interface, and enum under `src/`. For each:
  - Is the name idiomatic and consistent with neighbours? (e.g. `…Interface` suffix used or not, applied uniformly)
  - Are all parameters and return types narrowed as much as possible?
  - Are constructor signatures consistent in their use of named arguments / promoted properties?
  - Are there dead methods, unused parameters, or leftover yin idioms?
- [ ] **Confirm the three-layer surface composes correctly.** The schema layer (Phase 4.5), resource/hydrator layer (Phase 1, using yin's `Resource` serializer contract and `Hydrator` contract), and lower-level orchestrator (Phase 1) are all public API at 1.0. Verify by walking three independent consumer paths in throwaway scripts: (a) register only a schema, end-to-end works; (b) register a schema with a custom resource override, the override takes precedence; (c) register a resource + hydrator pair with no schema, behaves exactly as Phase 1 ships. Record results in the decision log.
- [ ] List every public type in `docs/api-inventory.md` (or similar) — useful both as a release-notes input and as a future BC reference
- [ ] PHPStan a level higher than usual: try level 10 / additional rules beyond the generics-related ones already adopted in Phase 1 / specific extensions. Triage the noise; commit only the fixes that genuinely improve type soundness. The generics-related rules from `phpstan-strict-rules` should already be on per Phase 1's API surface review; this is the moment to consider broader strict rules that were too noisy to adopt earlier.
- [ ] **Generics coverage final sweep.** Walk every public type added since Phase 1 (Phase 2's `Page` subclasses, Phase 4.5's `Field`, `Constraint`, `Filter`, `Sort`, `Schema`, `Server`) and confirm consumer-visible parametric types carry their `@template` parameters per the "Type system principles" section of `CLAUDE.md`. Any drift gets fixed here, before 1.0 locks the public surface.
- [ ] Confirm no `haddowg\JsonApi\…` type leaks an internal-only class through its public method signatures
- [ ] Walk every pattern entry in `CLAUDE.md` against the current state of the code; correct any drift. The playbook must be accurate for post-1.0 maintenance and post-1.0 candidate work.

### Performance baseline

- [ ] Add a minimal benchmark suite under `benchmarks/` (use `phpbench/phpbench` or a hand-rolled harness — decide during kick-off)
- [ ] Cover at minimum: serialise a moderate document (1 primary resource + 5 included), parse a moderate request, run the recommended middleware chain end-to-end on a representative request
- [ ] Record the baseline numbers in `docs/performance-baseline.md` with environment details (PHP version, hardware class, OS)
- [ ] Do **not** aim to optimise during this phase — the goal is establishing a baseline. Optimisation is a post-1.0 concern unless something is egregiously wrong.

### Security review

- [ ] Body-parsing review: is the `json_decode` call bounded (max depth)? Could a deeply nested document blow the stack? Confirm `JSON_THROW_ON_ERROR` is in use everywhere.
- [ ] Header parsing: confirm `Accept` / `Content-Type` parsers handle malformed input safely (no regex catastrophes, no unbounded loops).
- [ ] Error responses: confirm production-mode error responses don't leak internal information (stack traces, file paths, env vars) unless explicitly configured to.
- [ ] Dependencies: run `composer audit`; address any advisories.
- [ ] Document any security-relevant configuration the consumer should know about in `docs/security.md` (e.g. recommendation to put a body-size limit in front of the application, dev-mode error verbosity).

### CHANGELOG

- [ ] Confirm conventional-commit-driven CHANGELOG is current
- [ ] Add a 1.0 release entry summarising the major architectural decisions and the scope of this release
- [ ] Explicitly list what is **not** in 1.0 (atomic operations, attribute-driven hydrators) and link to their post-1.0 candidate entries in `docs/PLAN.md`

### Release procedure

- [ ] Confirm release-please's pending PR is current and reflects the 1.0 cut
- [ ] If release-please is producing an unexpected version (e.g. wants to cut a `0.x` minor instead of `1.0`), adjust per its [release-as](https://github.com/googleapis/release-please) convention or manually tag
- [ ] Tag `1.0.0`, publish on Packagist (`composer.json` should already be live; tagging triggers Packagist webhook)
- [ ] Verify install: `composer create-project haddowg/json-api ./test-install` in a clean directory; confirm it works
- [ ] Announce wherever appropriate (maintainer's call — GitHub release notes are the minimum)

### Post-release plan

- [ ] Confirm which post-1.0 candidate is next (atomic operations vs. attribute-driven hydrators) based on maintainer's priority and any consumer signal
- [ ] Generate the plan document for whichever is chosen (`docs/post-1.0-atomic-operations.md` or `docs/post-1.0-attributes.md`)
- [ ] If neither is starting immediately, the plan documents stay as placeholders to be generated when work begins

## Decision log

_(Appended to during execution.)_

| Date | Decision | Rationale | Affects |
|---|---|---|---|
| _yyyy-mm-dd_ | _(example: chose phpbench/phpbench for the benchmark harness)_ | _(rationale)_ | _(this phase / future phases)_ |

## Open questions

- Benchmarking harness: `phpbench/phpbench` (industry standard, more setup) vs. hand-rolled (less ceremony, fewer features). Decide during kick-off based on how much benchmarking effort is appetite-appropriate.
- PHPStan strict rules beyond the generics-related ones (`phpstan-strict-rules` already cherry-picked in Phase 1 for the generic-related rules): blanket adopt the rest, or cherry-pick? Lean: try them, keep what helps, ignore what doesn't with documented rationale.
- Should `docs/api-inventory.md` be auto-generated from reflection, or hand-curated? Auto-generated stays current; hand-curated is more useful as documentation. Decide during kick-off; lean auto-generated with a hand-written preamble.
- Security review depth: do we want a third-party review, or maintainer-only? Lean: maintainer-only for 1.0; third-party review is a future enhancement.
- Should the 1.0 release include an LTS commitment, or is "supported until further notice" sufficient? Lean: no formal LTS at 1.0; revisit if consumer demand emerges.

## Acceptance criteria

The phase is done when all of the following hold:

1. All task-list items are checked off.
2. `docs/spec-compliance.md` shows no `not-covered` rows for MUSTs (or every remaining one has a filed follow-up issue and is documented as a known gap).
3. Public API surface review is complete; any naming/consistency fixes are merged.
4. `docs/api-inventory.md` exists and lists every public type.
5. `docs/performance-baseline.md` exists with baseline numbers and environment details.
6. `docs/security.md` exists with consumer-facing recommendations.
7. `composer audit` reports zero advisories.
8. CHANGELOG entry for 1.0 is curated and merged.
9. `1.0.0` is tagged and published on Packagist.
10. A fresh `composer create-project haddowg/json-api` install succeeds and the getting-started example runs.

### Verification plan

```bash
# Pre-release sanity
composer install
composer test
composer phpstan
composer cs-check
composer audit                                 # zero advisories
vendor/bin/phpbench run --report=default       # benchmarks run; record output

# Spec coverage
for group in document-structure fetching-resources fetching-relationships \
             inclusion-of-related-resources sparse-fieldsets sorting \
             pagination filtering crud errors content-negotiation \
             extensions-and-profiles; do
  vendor/bin/phpunit --group "spec:$group" --testdox
done

# Post-release verification (in a clean directory)
composer create-project haddowg/json-api ./test-install
cd test-install
composer test
```

Manual review:

- Read the 1.0 CHANGELOG entry as if encountering it for the first time on Packagist — does it convey what the package is and what it does?
- Confirm `docs/PLAN.md` status table is fully updated: all phases `Complete`, post-1.0 candidates listed with current intent.
- Confirm GitHub release notes mirror or reference the CHANGELOG entry.

## Handover output

This is the final pre-1.0 phase. Handover is to ongoing maintenance and post-1.0 work, not to a numbered phase.

1. **Status table update** in `docs/PLAN.md` — Phase 6 → `Complete`. Master plan reflects that pre-1.0 phasing is closed.
2. **Post-1.0 plan document(s)** — `docs/post-1.0-atomic-operations.md` and/or `docs/post-1.0-attributes.md`, generated as full phase plans following the same structure (goal, scope, prerequisites, kick-off, tasks, decision log, open questions, acceptance criteria, handover).
3. **Maintenance notes** — a short `docs/maintenance.md` covering: release cadence expectations, supported PHP version policy, how to triage incoming issues, who can cut releases.
4. **Open questions resolved** — every entry in the Open questions section above has an answer recorded in the decision log. Resolve any remaining or newly-surfaced questions by asking the maintainer interactively using whatever ask-user-question tool the executor's environment provides.
5. **Decision log finalised** — phase-local decisions captured here; any decisions affecting ongoing maintenance promoted to `docs/maintenance.md` or `PLAN.md`.
