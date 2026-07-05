export const meta = {
  name: 'pivot-on-linkage',
  description: 'Fix: render belongsToMany meta.pivot on a primary-resource compound-include linkage (not just the relationship/related endpoints)',
  phases: [{ title: 'Build' }, { title: 'Review' }, { title: 'Fix' }],
}

const REPO = '/Users/gregory.haddow/Sites/json-api-symfony'

const SPEC = [
  'PROJECT: haddowg/json-api-symfony — a Symfony bundle making the framework-agnostic core haddowg/json-api',
  '(sibling checkout at /Users/gregory.haddow/Sites/json-api, on branch main, symlinked into vendor via a global',
  'Composer path repo) idiomatic in Symfony. PHP. Repo root ' + REPO + '. You are on branch',
  'fix/pivot-meta-on-included-linkage. Read CLAUDE.md for the executor playbook.',
  '',
  'THE BUG (a real gap, NOT intentional — confirmed: no ADR/doc/test says otherwise): a belongsToMany relation\'s',
  'per-member pivot values render as identifier meta.pivot on the RELATIONSHIP endpoint (GET /{type}/{id}/',
  'relationships/{rel}) and the RELATED endpoint (GET /{type}/{id}/{rel}), but NOT in a PRIMARY-resource document\'s',
  'relationships block. E.g. GET /playlists/{id}?include=orderedTracks returns data.relationships.orderedTracks',
  '.data[] identifiers carrying meta.served_by but NO meta.pivot. It must carry pivot wherever that relation\'s',
  'linkage data renders. NOTE the OpenAPI projection ALREADY types meta.pivot on the relationship component used in',
  'the primary resource\'s relationships block (PlaylistsOrderedTracksRelationship, an allOf with meta.pivot), so the',
  'runtime is currently OUT OF SPEC — this fix makes the runtime match the already-advertised schema.',
  '',
  'WHY IT HAPPENS (verified): the relationship endpoint binds Serializer/PivotParentSerializer, which substitutes',
  'the related type\'s serializer with Serializer/PivotMetaSerializer for that ONE relationship (via',
  'RelationInterface::buildRelationship + Serializer/PivotSubstitutingResolver), so each linkage identifier\'s',
  'meta.pivot comes from PivotMetaSerializer::getMeta (pivot rides core\'s getMeta path → core renders it into the',
  'resource identifier; NO core change needed). The PRIMARY read path (Operation/CrudOperationHandler::fetch())',
  'renders the parent via $server->serializerFor($type) with NO such substitution, so its pivot relations\' linkage',
  'gets the plain related serializer → no pivot.',
  '',
  'THE FIX (BUNDLE-ONLY — do NOT change core; pivot rides core getMeta unchanged):',
  '1. DataProvider/PivotAwareProviderInterface.php + DataProvider/Doctrine/DoctrineDataProvider.php: add a BATCHED',
  '   per-parent pivot-map method, e.g.',
  '     fetchRelatedPivotMapBatch(string $relatedType, array $parents, RelationInterface $relation): array',
  '   returning array<parentWireId, array<memberId, array<field,mixed>>> — the FULL association pivot map per parent',
  '   (no window, no filter), keyed by member id. Implement on Doctrine as ONE DQL over the association entity scoped',
  '   to the parent set (WHERE the near side IN the parents) — mirror the existing single-parent fetchRelatedPivotMap',
  '   but batched; reuse its pivot-value extraction. parentWireId must equal the wire id the parent serializer\'s',
  '   getId() produces for that parent. (Keying by member id means the map composes with windowing/filtering for free',
  '   — PivotMetaSerializer only looks up the members it actually renders and ignores the rest.) Only the Doctrine',
  '   provider implements PivotAwareProviderInterface; the in-memory provider does NOT (the documented pivot boundary',
  '   — leave it 400/no-pivot, unchanged).',
  '2. A new per-parent, multi-relation pivot parent serializer (e.g. Serializer/PivotLinkageParentSerializer)',
  '   decorating the parent serializer: given a set of pivot relations + their batched per-parent maps, override',
  '   getRelationships()[relName] for EACH pivot relation to rebuild it via $relation->buildRelationship($model, $req,',
  '   new PivotSubstitutingResolver($baseResolver, $relation->relatedTypes()[0], new PivotMetaSerializer(',
  '   $relatedSerializer, $mapForThisParent))) where $mapForThisParent = $batched[relName][$inner->getId($model)] ?? [].',
  '   Delegate every other method to the inner serializer. REUSE PivotMetaSerializer + PivotSubstitutingResolver.',
  '   Refactor/share with the existing single-parent single-relation PivotParentSerializer where it is clean to do so',
  '   (do not duplicate the substitution logic gratuitously); keep PivotParentSerializer behaviour intact (its tests',
  '   must still pass).',
  '3. Operation/CrudOperationHandler::fetch(): in BOTH the fetch-one branch and the collection branch, BEFORE building',
  '   the response (DataResponse::fromResource / fromPage), determine $type\'s belongsToMany relations whose linkage',
  '   data WILL render (the relation is includable+included OR its linkage renders by default i.e. NOT',
  '   emitsDataOnlyWhenLoaded) AND for which the provider supportsPivot(). For those, fetch the batched per-parent',
  '   pivot maps over the rendered $items and wrap $serializer in the new pivot parent serializer. When there are none,',
  '   leave $serializer untouched (zero overhead for non-pivot types). Compose cleanly with the existing',
  '   applyRelationshipWindows()/applyRelationshipCounts() (the member-id-keyed map composes; verify no regression to',
  '   the windowed-profile path or the relationship/related endpoints).',
  '',
  'CONVENTIONS (CLAUDE.md): Conventional Commits; ADRs under docs/adr/ following docs/adr/ADR-FORMAT.md (pick the next',
  'free number — the existing range goes well past 0100; list docs/adr and use max+1). PHPStan level 9. PER-CS 2.0,',
  'and CS DISABLES global_namespace_import so reference global classes/functions fully-qualified inline (\\Exception,',
  '\\assert, \\dirname) — never import them. Match the surrounding terse, heavily-documented house style of the pivot',
  'serializers. Gates: `composer test` (PHPUnit, attributes only), `composer phpstan`, `composer cs-check`. The',
  'functional test kernels boot debug=false with a FIXED cache dir, so a DI/kernel change needs the compiled test',
  'container cleared — before functional runs, delete the dirs $(php -r "echo sys_get_temp_dir();")/json-api-symfony-tests',
  'and .../json-api-symfony-examples (macOS tmp is under /tmp/claude-501, NOT /tmp).',
  '',
  'TESTS (dual-provider, mirror the existing pivot suites): tests/Functional/DoctrinePivotRelatedCollectionTest.php,',
  'tests/Functional/InMemoryPivotBoundaryTest.php, tests/Functional/SeedsDoctrinePivot.php show the patterns + the',
  'helpers (fetchDocument, byId, pivotField). The example app (examples/music-catalog-symfony) has the witness:',
  'playlists.orderedTracks (pivot fields position/weight/addedAt on the PlaylistEntry association entity).',
].join('\n')

const BUILD_REPORT = {
  type: 'object',
  additionalProperties: false,
  required: ['summary', 'filesChanged', 'gates'],
  properties: {
    summary: { type: 'string' },
    filesChanged: { type: 'array', items: { type: 'string' } },
    testsAdded: { type: 'array', items: { type: 'string' } },
    gates: {
      type: 'object',
      additionalProperties: false,
      required: ['test', 'phpstan', 'csCheck'],
      properties: {
        test: { type: 'boolean' },
        phpstan: { type: 'boolean' },
        csCheck: { type: 'boolean' },
      },
    },
    gateOutput: { type: 'string' },
    notes: { type: 'string' },
  },
}

const FINDINGS = {
  type: 'object',
  additionalProperties: false,
  required: ['findings'],
  properties: {
    findings: {
      type: 'array',
      items: {
        type: 'object',
        additionalProperties: false,
        required: ['severity', 'title', 'detail', 'location', 'recommendation'],
        properties: {
          severity: { type: 'string', enum: ['blocker', 'major', 'minor', 'nit'] },
          title: { type: 'string' },
          detail: { type: 'string' },
          location: { type: 'string' },
          recommendation: { type: 'string' },
        },
      },
    },
  },
}

phase('Build')

const build = await agent(
  SPEC +
    '\n\n=== YOUR TASK: implement the fix exactly as specified above ===\n' +
    'Implement steps 1-3 (the batched provider method + Doctrine DQL, the per-parent multi-relation pivot parent\n' +
    'serializer, and the CrudOperationHandler wiring for fetch-one + collection). Add dual-provider functional tests:\n' +
    '- Doctrine: a compound include (GET /playlists/{id}?include=orderedTracks) AND a collection render assert\n' +
    '  data.relationships.orderedTracks.data[].meta.pivot carries position/weight/addedAt for the correct members;\n' +
    '  also assert the linkage carries pivot WITHOUT ?include (default-rendered linkage). Mirror\n' +
    '  DoctrinePivotRelatedCollectionTest + SeedsDoctrinePivot.\n' +
    '- In-memory: the documented no-pivot boundary is unchanged (extend/keep InMemoryPivotBoundaryTest).\n' +
    '- Confirm the existing relationship-endpoint and related-endpoint pivot tests STILL PASS (no regression).\n' +
    'Add an ADR (docs/adr/, next free number, per ADR-FORMAT.md) recording the decision. Update the relevant docs\n' +
    'page (pivot/relationships) noting pivot now renders on a primary-resource linkage too.\n' +
    'Before finishing: clear the test/example compiled-container temp dirs (see SPEC), then run `composer test`,\n' +
    '`composer phpstan`, `composer cs-check`. Report honestly per gate; put any failing tail in gateOutput. Do NOT\n' +
    'commit. Do NOT touch the core repo.',
  { label: 'implement-pivot-linkage', phase: 'Build', schema: BUILD_REPORT },
)

phase('Review')

const reviewBriefStr =
  SPEC +
  '\n\n=== WHAT WAS BUILT ===\n' + (build?.summary ?? '') +
  '\nFiles: ' + JSON.stringify(build?.filesChanged ?? []) + '\nGates self-reported: ' + JSON.stringify(build?.gates ?? {}) +
  '\nNotes: ' + (build?.notes ?? '') +
  '\n\nYou are an ADVERSARIAL reviewer. READ THE ACTUAL CODE and run gates as needed. Verify against the real ' +
  'behaviour and the example fixtures. Concrete, evidence-backed findings (file:line, actual vs expected). Prefer ' +
  'a few real blockers/majors over many nits.'

const reviews = await parallel([
  () =>
    agent(
      reviewBriefStr +
        '\n\n=== LENS: CORRECTNESS OF THE SEAM ===\nIs pivot now on the primary-resource linkage for BOTH fetch-one\n' +
        'and collection, included AND default-rendered? Is the batched pivot map keyed correctly (parent wire id /\n' +
        'member id) so the right member gets the right pivot — especially a member that appears under MULTIPLE\n' +
        'parents (per-edge pivot must differ)? Is the Doctrine batch ONE query (no N+1 across parents)? Does the\n' +
        'parent-serializer decorator resolve the per-parent map by the CURRENT object? Confirm the empty/absent map\n' +
        'cases (parent with no pivot members) render cleanly.',
      { label: 'review:seam-correctness', phase: 'Review', schema: FINDINGS },
    ),
  () =>
    agent(
      reviewBriefStr +
        '\n\n=== LENS: REGRESSION SAFETY ===\nDoes this break or double-apply pivot on the relationship/related\n' +
        'endpoints (which already bind PivotParentSerializer)? Does it compose with applyRelationshipWindows (the\n' +
        'profile window) and applyRelationshipCounts without leaking the windowed page or mismatching pivot vs\n' +
        'rendered members? Any overhead/extra query for NON-pivot types or when the relation linkage is not rendered\n' +
        '(lazy dataOnlyWhenLoaded)? Is the in-memory boundary genuinely unchanged? Run `composer test` and confirm\n' +
        'the full suite (incl. existing pivot + windowing tests) is green.',
      { label: 'review:regression', phase: 'Review', schema: FINDINGS },
    ),
  () =>
    agent(
      reviewBriefStr +
        '\n\n=== LENS: TEST RIGOUR, ADR & STYLE ===\nDo the new tests actually assert pivot VALUES on the primary\n' +
        'linkage (dual-provider: Doctrine present, in-memory boundary) incl. the no-include default-render case and a\n' +
        'collection? Would they fail against the OLD code (i.e. they pin the bug)? Is the ADR present, correctly\n' +
        'numbered and formatted, and the doc updated? PHPStan L9 + PER-CS 2.0 clean (no imported global symbols —\n' +
        'fully-qualified inline)? House style matches the existing pivot serializers? Run composer phpstan + cs-check.',
      { label: 'review:tests-adr-style', phase: 'Review', schema: FINDINGS },
    ),
])

const allFindings = reviews.filter(Boolean).flatMap((r) => r.findings ?? [])
const blocking = allFindings.filter((f) => f.severity === 'blocker' || f.severity === 'major')
log('Review surfaced ' + allFindings.length + ' findings; ' + blocking.length + ' blocker/major.')

phase('Fix')

let fix = null
if (blocking.length > 0) {
  fix = await agent(
    SPEC +
      '\n\n=== APPLY FIXES ===\nVerify each finding against the code before fixing; if one is wrong, note it and\n' +
      'skip. Keep it bundle-only (no core change), preserve the relationship/related-endpoint pivot behaviour and the\n' +
      'in-memory boundary. Re-run ALL gates (clear the temp container dirs first).\n\n' +
      'BLOCKER/MAJOR:\n' +
      blocking.map((f, i) => i + 1 + '. [' + f.severity + '] ' + f.title + ' (' + f.location + ')\n   ' + f.detail + '\n   FIX: ' + f.recommendation).join('\n') +
      '\n\nQuick correct MINORS:\n' +
      allFindings.filter((f) => f.severity === 'minor').map((f) => '- ' + f.title + ' (' + f.location + ')').join('\n') +
      '\n\nGates: `composer test`, `composer phpstan`, `composer cs-check`. Report honestly; failing tail in gateOutput.',
    { label: 'apply-fixes', phase: 'Fix', schema: BUILD_REPORT },
  )
} else {
  log('No blocking findings — skipping fix phase.')
}

return { build, findings: allFindings, blockingCount: blocking.length, fix }
