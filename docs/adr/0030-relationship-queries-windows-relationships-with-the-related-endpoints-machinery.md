# Relationship Queries windows rendered relationships with the related endpoint's machinery, out of band

Under the negotiated Relationship Queries profile, a `relatedQuery[<rel>]` sort or filter on a primary request windows each rendered to-many to page 1 with the related endpoint's vocabulary, validation and pagination, and renders a to-one as `null` when the filter excludes its target. The windowed result reaches core through its relationship-linkage and pagination seams rather than being written onto the parent, because a write leaks onto any sibling relation sharing the backing column and, on Doctrine, flips a lazy collection to "loaded" so a links-only relation starts rendering data.

## Consequences

- A to-many is windowed only when its linkage data renders (included, or `withData()`); an unknown key on one that does not render still `400`s.
- Only the primary resource's top-level relations are addressable, and `[sort]` or `[page]` on a to-one is a `400`.
- Relationship pagination links use the spec's plain form against the relationship endpoint, never the profile's `relatedQuery` form.
