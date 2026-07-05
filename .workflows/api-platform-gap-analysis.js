export const meta = {
  name: 'api-platform-gap-analysis',
  description: 'Comparative gap analysis: API Platform (Symfony-native) vs our json-api core+bundle, through the narrow lens of "building/integrating a JSON:API in Symfony" — API surface + DX. Tangential AP features (GraphQL/Hydra/admin/Mercure/alt-stores) are NON-GOALS. Produces docs/api-platform-gap-analysis.md (untracked, no commit).',
  phases: [
    { title: 'Survey', detail: 'six parallel dimension agents characterise API Platform (current 4.x, web-verified) vs our current surface and list concrete gaps with relevance/value/effort tags' },
    { title: 'Synthesize', detail: 'one agent consolidates into a single ranked report docs/api-platform-gap-analysis.md: real gaps vs already-have vs non-goal, an executive set, OpenAPI flagged as an accepted roadmap item' },
  ],
}

const CONTEXT = `
TASK: a COMPARATIVE GAP ANALYSIS of **API Platform** (the Symfony-native, idiomatic API framework) against OUR
packages — the framework/storage-agnostic core \`haddowg/json-api\` (/Users/gregory.haddow/Sites/json-api) and its
Symfony bundle + Doctrine reference adapter \`haddowg/json-api-symfony\` (/Users/gregory.haddow/Sites/json-api-symfony).

THE LENS (critical — keep tightly to it): API Platform is FAR broader than JSON:API and supports the JSON:API format
only to a LIMITED extent. We are NOT interested in everything tangential it does. The ONLY question is:
"As someone who wants to BUILD and INTEGRATE a JSON:API in a Symfony app, how does API Platform's **API surface** and
**developer experience (DX)** compare to ours, and is there anything OBVIOUS API Platform caters for that we do NOT
yet?" Judge every candidate gap by its value TO A JSON:API AUTHOR/INTEGRATOR.

EXPLICIT NON-GOALS (mark these as out-of-scope; do NOT log them as gaps): GraphQL, Hydra / JSON-LD / the JSON-LD
vocabulary, Mercure / real-time, the React admin / create-react-admin, alternative persistence (ElasticSearch,
MongoDB/ODM) EXCEPT where it illustrates a DX abstraction we lack, the API Platform distribution/Docker scaffolding.
A note that AP supports these is fine as one line under NON-GOALS, not a gap.

GIVEN (already accepted onto our roadmap — do not belabour proving it, just characterise the DX + what we'd need):
**generated OpenAPI documentation** (OpenAPI/Swagger export + Swagger UI/ReDoc + JSON Schema). Greg wants this; treat
it as an accepted roadmap item and describe what AP gives and what an equivalent for us would entail.

OUR CURRENT SURFACE (read to know what we ALREADY have, so gaps are accurate + attributable). Bundle docs:
/Users/gregory.haddow/Sites/json-api-symfony/docs/ (getting-started, resources, relationships, validation, data-layer,
custom-data-providers, custom-serializers-hydrators, capability-composition, routing, errors, security, authorization,
configuration, doctrine, multi-server-and-testing, lifecycle-hooks). Bundle ADRs:
/Users/gregory.haddow/Sites/json-api-symfony/docs/adr/ (0001-0051). Core CLAUDE.md +
/Users/gregory.haddow/Sites/json-api/docs/ + ADRs. The bundle CLAUDE.md
(/Users/gregory.haddow/Sites/json-api-symfony/CLAUDE.md) summarises the whole architecture + phases. The prior
Laravel comparison lives at /Users/gregory.haddow/Sites/json-api-symfony/docs/laravel-gap-analysis.md +
laravel-gap-build-plan.md — read them so this report COMPLEMENTS (does not duplicate) that one and reuses its
gap-id space conceptually where the same gap recurs (note "= Laravel gap #N" when it overlaps). What we ALREADY have
(do NOT re-log as gaps): resource declaration via AbstractResource + capability composition + #[AsJsonApiResource];
auto route loading; read (sparse fieldsets, sort, filter incl. relationship-existence + relation-scoped + pivot
filter/sort, pagination incl. max-per-page + configurable default paginator); ?include with safeguards
(per-relation + allowed-paths whitelist + max depth) + a Doctrine include preloader; writes (POST/PATCH/DELETE) over
a DataPersister SPI with a Symfony Validator bridge (rich constraint vocab, cross-field, entity-level, merge-before-
validate); relationships (linkage/links, related + relationship endpoints, mutation, polymorphic); a DataProvider/
DataPersister SPI with Doctrine + in-memory impls; custom id encoding + id source/policy; lifecycle hooks
(per-operation events + resource methods + serving); declarative authorization (security expressions on the
resource attribute, riding the hooks); self links; filter-value validation; and an in-progress testing browser
(JsonApiBrowser).

RESEARCH DISCIPLINE: GROUND claims in the CURRENT API Platform docs (the current stable major is 4.x — verify;
api-platform.com/docs). USE web search/fetch (find the tools via ToolSearch: WebSearch / WebFetch) to confirm
specifics rather than relying on memory — AP's API changes across majors (e.g. the move to the metadata #[ApiResource]
attribute + state providers/processors replacing the old data providers/persisters + ItemDataProvider). Cite the
doc URLs you used. Where you are unsure, say so rather than inventing. Note the AP version your claims target.

OUTPUT STYLE: concrete and comparative. For each gap: what AP does (the feature + a tiny code/DX sketch), what we
have today (or "nothing"), how relevant it is to a JSON:API builder (high/med/low), rough value (high/med/low) +
effort (S/M/L) + which layer it lands in (core / bundle / both), and whether it overlaps an existing Laravel gap.
This is ANALYSIS ONLY — NO code changes, NO commits, do NOT touch src/ or tests/.
`

const DIMENSION_SCHEMA = {
  type: 'object', additionalProperties: false,
  required: ['dimension', 'apVersion', 'apSummary', 'ourCurrentState', 'gaps', 'nonGoals', 'sources'],
  properties: {
    dimension: { type: 'string' },
    apVersion: { type: 'string', description: 'the API Platform version your claims target (verify, e.g. 4.x)' },
    apSummary: { type: 'string', description: 'how API Platform handles this dimension, with the DX shape' },
    ourCurrentState: { type: 'string', description: 'what our core+bundle already provide in this dimension' },
    gaps: { type: 'array', items: { type: 'object', additionalProperties: false,
      required: ['title', 'whatAPDoes', 'whatWeHave', 'relevanceToJsonApi', 'value', 'effort', 'layer', 'overlapsLaravelGap', 'notes'],
      properties: {
        title: { type: 'string' },
        whatAPDoes: { type: 'string' },
        whatWeHave: { type: 'string', description: '"nothing" or what partial thing we have' },
        relevanceToJsonApi: { type: 'string', enum: ['high', 'medium', 'low'] },
        value: { type: 'string', enum: ['high', 'medium', 'low'] },
        effort: { type: 'string', enum: ['S', 'M', 'L'] },
        layer: { type: 'string', enum: ['core', 'bundle', 'both'] },
        overlapsLaravelGap: { type: 'string', description: 'the Laravel gap #N it overlaps, or "none"' },
        notes: { type: 'string' },
      } } },
    nonGoals: { type: 'array', items: { type: 'string' }, description: 'AP features in this dimension that are out-of-scope for a JSON:API builder, one line each' },
    sources: { type: 'array', items: { type: 'string' }, description: 'doc URLs consulted' },
  },
}

const DIMENSIONS = [
  { key: 'resource-declaration', title: 'Resource declaration, operations & routing DX',
    focus: `How AP declares an API resource and its operations: the #[ApiResource] attribute + per-operation
metadata (Get/GetCollection/Post/Patch/Delete + custom operations), uriTemplate/uriVariables, sub-resources/nested
routes, operation naming, output/input class binding, the overall "declare a resource" ergonomics and conventions.
Compare to our AbstractResource + capability composition + #[AsJsonApiResource] + the auto route loader + per-type
operation allow-list. Hunt gaps: custom/non-CRUD operations (actions), sub-resource routing ergonomics, declaring an
API from a plain class/DTO not tied to an entity, alternate-representation operations, resource-name/route conventions,
config-vs-attribute DX.` },
  { key: 'query-layer', title: 'Query layer: filtering, sorting, pagination, parameters',
    focus: `AP's filter ecosystem (SearchFilter, OrderFilter, RangeFilter, DateFilter, BooleanFilter, NumericFilter,
ExistsFilter, the #[ApiFilter] attribute, custom filters, parameter/QueryParameter metadata + validation), pagination
(page + CURSOR-based + partial pagination + client-controlled page size + max items), and parameter declaration DX.
Compare to our filter/sort/pagination (incl. filter-value validation, relationship-existence + relation-scoped +
pivot filters, max-per-page, configurable default paginator). Hunt gaps: cursor pagination, declarative filter
ergonomics (#[ApiFilter]-style), exposed/auto-documented filter metadata, property-level search semantics, parameter
validation surfaced in docs, a built-in filter library vs author-written handlers.` },
  { key: 'write-validation', title: 'Writes: state processors, DTOs, validation, denormalization',
    focus: `AP's write path: state processors, input/output DTOs (decoupling the wire shape from the entity),
(de)serialization groups, Symfony Validator integration incl. validation groups per operation, write security,
processor chaining/decoration. Compare to our DataPersister SPI + core hydrator + Symfony Validator bridge (rich
vocab, cross-field, entity-level, merge-before-validate) + lifecycle hooks. Hunt gaps: first-class input/output DTO
decoupling, per-operation validation groups, partial-update semantics, bulk/batch writes, processor composition DX.` },
  { key: 'serialization', title: 'Serialization & representation DX',
    focus: `AP's serialization: normalization/denormalization (serialization) GROUPS, computed/virtual properties,
custom normalizers, name converters, IRIs + links, max depth, circular-reference handling, per-operation
normalization context. Compare to our serializer/fields/relations + sparse fieldsets + links. Hunt gaps: groups-style
contextual field selection beyond sparse fieldsets, computed-property ergonomics, custom-normalizer extension DX,
output-format negotiation. (JSON-LD/Hydra representation itself = NON-GOAL; the serialization DX mechanics are in
scope.)` },
  { key: 'openapi-docs', title: 'OpenAPI / API documentation generation (accepted roadmap item)',
    focus: `AP's documentation generation: OpenAPI (v3) export, Swagger UI + ReDoc, JSON Schema generation from the
metadata, per-operation/parameter/response documentation, examples, customising the OpenAPI doc (decorators), the
"docs are free from the metadata" DX. This is an ACCEPTED roadmap item for us — characterise what AP gives, the DX
expectation it sets, AND what an equivalent would entail for us (we have rich resource/field/relation/filter metadata
+ a JSON:API structure to derive a spec from; JSON:API + OpenAPI has known modelling friction worth noting). Also
note JSON Schema export + Postman/other exports. Our current state: none.` },
  { key: 'cross-cutting', title: 'Security, errors, content-negotiation, HTTP caching, extension, testing, versioning',
    focus: `The cross-cutting surface: security (security/securityPostDenormalize attributes, voters, per-operation)
vs our declarative authorization; the error model (AP's RFC 7807/Problem+JSON, exception->status mapping, validation
error shape) vs our JSON:API error documents + exception listener; content negotiation/formats; HTTP CACHING (cache
headers, Vary, Etag, cache invalidation / cache tags / Varnish/Souin purge) — likely a real gap for us; extension
points (state provider/processor decoration, query extensions, event/kernel hooks) vs our SPI + lifecycle hooks +
DoctrineExtension; the TESTING story (ApiTestCase + the AP assertion client, JSON Schema assertions) vs our
in-progress JsonApiBrowser; API versioning; rate-limiting; deprecations/sunset. Hunt the obvious gaps a JSON:API
builder would feel — especially HTTP caching + any testing assertions we lack.` },
]

// ---------------------------------------------------------------------------
phase('Survey')
const surveys = await parallel(DIMENSIONS.map(d => () => agent(
  `You are surveying ONE dimension of an API Platform vs ours gap analysis.

${CONTEXT}

YOUR DIMENSION: "${d.title}".
FOCUS: ${d.focus}

Read the relevant parts of OUR docs/ADRs/code to establish what we already have in THIS dimension, then characterise
API Platform's offering (web-verify the current 4.x specifics + cite URLs), then enumerate the concrete GAPS (things
AP caters for that we do not) judged by value to a JSON:API builder. Be honest where we already match or exceed AP
(log nothing there). Return the structured result for this dimension only.`,
  { label: `survey:${d.key}`, phase: 'Survey', schema: DIMENSION_SCHEMA },
)))
const dims = surveys.filter(Boolean)
const totalGaps = dims.reduce((n, d) => n + (d.gaps ? d.gaps.length : 0), 0)
log(`Survey: ${dims.length}/${DIMENSIONS.length} dimensions, ${totalGaps} raw gaps.`)
if (dims.length === 0) { return { stoppedAfter: 'Survey', error: 'no dimensions returned' } }

// ---------------------------------------------------------------------------
phase('Synthesize')
const synthesis = await agent(
  `Consolidate the API Platform gap-analysis dimension surveys into ONE ranked report.

${CONTEXT}

THE SIX DIMENSION SURVEYS (JSON):
${JSON.stringify(dims, null, 2)}

WRITE the report to /Users/gregory.haddow/Sites/json-api-symfony/docs/api-platform-gap-analysis.md as an UNTRACKED
working doc — do NOT git add or commit it (it mirrors docs/laravel-gap-analysis.md, which is untracked). Structure it
like that prior report so they sit side by side:
 1. A short intro stating the LENS (building/integrating a JSON:API in Symfony — surface + DX) + the AP version
    surveyed + the EXPLICIT NON-GOALS (GraphQL/Hydra/JSON-LD/Mercure/admin/alt-stores).
 2. An EXECUTIVE SET: the handful of OBVIOUS, high-value gaps a JSON:API builder would feel — lead with **generated
    OpenAPI docs** (accepted roadmap item) and then the next most compelling (likely candidates from the surveys:
    HTTP caching, cursor pagination, declarative filter ergonomics, input/output DTO decoupling, testing assertions —
    but rank by what the surveys actually substantiate).
 3. A full TABLE of every real gap: id | dimension | gap | what AP does | what we have | relevance | value | effort |
    layer | overlaps-Laravel-#. Dedup across dimensions; merge duplicates.
 4. A "WE ALREADY MATCH/EXCEED" section (brief) so the report is honest about where we are not behind.
 5. NON-GOALS (one line each) so the scope is explicit.
 6. A short SUGGESTED ROADMAP ORDER for the real gaps (cheap wins vs larger), noting OpenAPI is already accepted.
Cross-reference the Laravel gaps where they overlap (so we don't double-count in planning). Keep it tight and
decision-useful — this feeds Greg's build prioritisation, not a survey dump.

Return a concise summary: the executive-set gap titles, the total real-gap count, and the report path. NO commits.`,
  { label: 'synthesize', phase: 'Synthesize' },
)

return {
  feature: 'API Platform gap analysis',
  dimensionsSurveyed: dims.length,
  rawGapCount: totalGaps,
  report: '/Users/gregory.haddow/Sites/json-api-symfony/docs/api-platform-gap-analysis.md',
  synthesis,
}
