# A shipped test trait validates real responses against the generated OpenAPI schemas, proving the document describes the API

The headline OpenAPI guarantee (design §8, D11/G6) is *round-trip*: the document the
bundle generates must actually describe the responses the bundle serves — a document that
drifts from reality is worse than none. So the bundle ships a reusable test trait,
`SchemaConformanceTrait`, with `assertResponseMatchesGeneratedSchema($response, $type,
SchemaDocumentKind $kind, ?$relationship, ?$server)`: it builds the document once per
server through the **same `DocumentFactory`** the warmer/controller/CLI use (so the
validated schema is byte-for-byte the served one), registers it under a stable `$id`, and
validates the real response body against the chosen envelope component's internal
`#/components/schemas/<Component>` pointer.

Key decisions:

- **Shipped in `src/Testing/`, not test-only**, so an *app's* functional suite can assert
  its own responses conform — the conformance guarantee is a feature for integrators, not
  just the bundle's internal proof.
- **Validation needs no vendored meta-schema.** `opis/json-schema` implements the JSON
  Schema 2020-12 dialect the projection targets **natively**, so registering only the
  generated document (and validating against an internal `$ref`) is sufficient and fully
  offline — the 2020-12 dialect fixtures the document *meta*-validation uses (Slice 4)
  are not needed here.
- **Component naming is shared with core.** The `SchemaDocumentKind` enum maps a response
  shape to its component-name suffix through core's `ComponentNaming` — the same naming
  the projector emits — so the trait never hard-codes a name the projection might change.
- **Proven on both providers.** A dual-provider `OpenApiConformanceTestCase` runs the
  identical single / collection / compound (`?include`) / related / relationship
  assertions against the in-memory and Doctrine kernels; a backed-enum witness
  (`products.status`) covers the named-enum-component path, and a negative-control test
  proves a deliberately out-of-vocabulary value **fails** (the guarantee has teeth).

Building this surfaced — and the helper *correctly caught* — a fixture inconsistency: the
shared `articles` test resource declared a non-nullable `address` Map whose serializer
returned `null` for an empty address, so a real `address: null` response did not validate
against the (correct) non-nullable object schema. The fix was to make the declaration
honest (`Map::make('address')->nullable()`), not to weaken the projection — a non-nullable
Map *should* project a non-nullable object, and a field that can serialize null *should*
be declared nullable. That the conformance helper flagged the mismatch is exactly its
purpose.
