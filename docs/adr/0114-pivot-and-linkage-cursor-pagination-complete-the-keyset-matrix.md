# Pivot-related and linkage cursor pagination complete the keyset matrix

- **Status:** accepted

The two follow-ups ADR 0113 left out of scope land together, on top of core's
hoisted keyset machinery: the pivot-backed `belongsToMany` related endpoint and
the relationship-linkage `GET` now page by keyset under a relation-declared
`CursorPaginator`, closing the bundle's cursor coverage matrix
(primary → related → pivot-related → linkage).

**The local keyset copies are gone.** Core #136 hoisted the four
provider-agnostic classes (`KeysetResolver`, `KeysetColumn`, `InMemoryKeyset`,
`CursorTokenMinter`) to `haddowg\JsonApi\Collection\Keyset` (core ADR 0123), so
the bundle now imports them instead of carrying byte-identical copies — one
signature change absorbed at the call sites (core's `KeysetResolver::resolve()`
takes the sort inputs directly rather than the bundle's `CollectionCriteria`).
Only the store-specific `DoctrineKeyset` (DQL `ORDER BY`/`WHERE` builder) stays
in the bundle. The existing cursor conformance suites are the contract: they
pass unchanged.

**Pivot-related cursor.** The Doctrine pivot tail
(`fetchRelatedPivotCollection`) grows a cursor arm mirrored **in place**, for
the same reason its offset branches already are: the shared
`WindowExecutor::runCursor()` is generic over object entities, while a pivot
fetch windows "mixed" rows (`[0 => farEntity, 'pivot_<field>' => scalar]`).
The arm reuses every shared piece — core `KeysetResolver` for the columns, the
bundle's `DoctrineKeyset` for the forced NULL=largest `ORDER BY` and the keyset
`WHERE`, core `CursorTokenMinter` for the tokens — with two pivot-specific
seams:

- the keyset can span **two aliases** (a related sort column on the far-entity
  root, a pivot sort column on the `pivot` join). A `KeysetColumn` deliberately
  carries only column + direction, so the column → alias routing is derived at
  SQL-build time from the criteria's key-routed `aliasOf` map (ADR 0059), and
  `DoctrineKeyset` takes it plus the association entity's metadata so a pivot
  boundary value still binds with the right DBAL type;
- the minter's row reader resolves a pivot column to its `pivot_<field>` scalar
  riding the row (a hidden pivot field in the keyset is selected just for the
  mint — it still never renders) and a root column to the hydrated far entity.

The boundary tokens and the pivot map therefore come off the **same single
query**, preserving the pivot path's core invariant. The result is a
`PivotCursorCollectionResult` — core's `CursorCollectionResult` (non-final,
`windowed: true` by construction) extended with the `pivotMap`, the cursor twin
of how `PivotCollectionResult` extends `CollectionResult` — so the handler's
existing `instanceof CursorCollectionResult` narrow and count-free guard read
it unchanged, and the pivot related arm renders through the same
`fromBoundaries` branch as its non-pivot twin, wrapped in the
`PivotMetaSerializer`. The in-memory provider needs **no pivot arm**: it is not
pivot-aware (the documented boundary), so the same declaration routes through
the plain related fetch whose cursor branch ADR 0113 already added — identical
page walks, no pivot vocabulary or meta.

**Linkage cursor.** `supplyWindowedRelationship` narrows on
`CursorCollectionResult` and builds the page through
`CursorPaginator::fromBoundaries` (the count-based `paginate*` conveniences are
deliberately token-less on a cursor paginator), so the relationship-object
links carry the minted `page[before]`/`page[after]` cursors at the relationship
URL. The built page is attached to the response via the **new core
`IdentifierResponse::withPage()`** (core ADR 0124), whose only effect is
advertising the page's profile (`jsonapi.profile` + the `Content-Type`
`profile` parameter) exactly as `RelatedResponse::fromPage` does — a linkage
body stays links-only (no `meta.page`), so the seam is profile-advertisement,
not rendering. The existing `CursorPaginator` exclusions around the
`wantsCount` decision are kept deliberately: a keyset page is count-free by
design, and the fetch itself already flowed the `CursorWindow`.

The dual-provider `PivotRelatedCursorConformanceTestCase` and
`LinkageCursorConformanceTestCase` extend the ADR 0113 conformance family over
the extended `cursorShelves` fixtures (a `pivotWidgets` `belongsToMany` with a
`slot` pivot column, seeded from one shared slot map), asserting identical page
walks on both providers, the Doctrine-only pivot surface (per-member
`meta.pivot`, the pivot-aliased `?sort=slot` keyset with the far-PK tiebreak,
stale-cursor `400`s), and the in-memory `400` boundary.
