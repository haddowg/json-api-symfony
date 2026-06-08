# Filter defaults are folded into the requested map in the shared CriteriaApplier

Core declares overridable filter defaults on the filter value objects (core
ADR 0017) and ships `FilterDefaults::apply()` as the one home of the folding
semantics; the bundle has to choose *where* to call it. Folding in the
`ReadOperationHandler` (once per request, before building the
`CollectionCriteria`) would mean either rebuilding core's `QueryParameters` VO
with the merged map or widening `CollectionCriteria` to carry an effective
filter map alongside the raw one. Folding instead in `CriteriaApplier` — the
shared matching step every provider already runs — keeps the change to one line
at the exact point the requested filter map meets the declared vocabulary, so
**both** providers honour defaults with no per-provider work and no new seam,
and because the fold happens before the filters reach a handler it also narrows
the pre-window `COUNT`, so paginated totals describe the defaulted collection.

The cost is that the applier's role widens slightly — from *match only* to
*fold-then-match* — but the defaulting logic itself still lives in core; the
applier only chooses the call site. The in-memory provider remains the
attributable witness: defaults are proven by the same dual-provider conformance
pair as the rest of Phase 1's read surface.
