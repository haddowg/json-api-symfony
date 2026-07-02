# Standalone /schemas.json documents are attribute-specific, relationships permissive

The per-type standalone JSON Schema documents (`/schemas.json`, `JsonSchemaFactory`)
and the OpenAPI document both project a type's resource object from the same core
`SchemaProjector`. But the OpenAPI document additionally narrows `relationships`,
`links` and `meta` by `$ref`-ing shared and per-relation components
(`OpenApiProjector`), which a self-contained JSON Schema document has no `components`
section to reference — so those members stay the projector's permissive `{type: object}`
placeholders. The factory docblock previously claimed the two "agree", which was true
only for attributes.

We scope the claim honestly: the standalone document is **authoritative for a type's
attribute shape** (byte-identical to the OpenAPI attributes), while the OpenAPI document
is the **fuller contract for relationships/links/meta**. We deliberately do not inline
the per-relation linkage into the standalone form for v1 — it would reimplement the
OpenAPI relationship projection in a component-free shape for the not-yet-built TS
validation seam. Inlining it later is a clean, additive enhancement.
