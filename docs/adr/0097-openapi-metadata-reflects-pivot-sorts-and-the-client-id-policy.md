# OpenAPI metadata reflects pivot sorts and the full client-id policy

The bundle feeds core's OpenAPI projector through its `MetadataSource` /
`TypeMetadata` / `RelationMetadata` adapters. Two facts the runtime acts on were not
reaching the projector, so the generated document under-described the served API
(consuming core ADR 0097, which aligns the projection with the runtime):

- **Pivot sort tokens.** A `belongsToMany` relation's pivot fields auto-derive a
  `?sort=<field>` vocabulary the runtime honours on its related/relationship endpoints
  (the pivot-aware provider sets `includePivotFields`, and `RelationCriteriaFactory`
  merges `PivotFields::sortsFor`), but `RelationMetadata::sorts()` returned only the
  relation's own `sorts()`. It now merges in `PivotFields::sortsFor($relation)` —
  empty for a non-pivot relation — so the OpenAPI `sort` enum advertises the pivot
  tokens, mirroring how the author-declared pivot **filters** (in `filters()`) are
  already advertised.

- **The require-client-id policy.** Core's `TypeMetadataInterface` gained
  `requiresClientId()` so the create-request schema can mark `id` required (vs merely
  permitted). `IdEncoderResolver::requiresClientIdFor()` surfaces the id field's
  `requireClientId()` policy, `MetadataSource` threads it into `TypeMetadata`, and the
  bundle's `TypeMetadata` implements the new method. A `requireClientId()` type's
  create body now requires `id`; a forbidden-id type's create body forbids it; an
  `allowClientId()` type's stays optional — matching the runtime's `403`s.

Both are exercised end-to-end by the example `OpenApiDocsTest` (the `orderedTracks`
pivot relation's `sort` enum carries `position`/`weight`; the `genres` type, declaring
`Id::make()->requireClientId()`, marks its create `id` required).
