# The bundle registers the Relationship Counts profile on every server

`ServerFactory` registers core's `RelationshipCountsProfile` (core ADR 0065) on
every server it builds, alongside the Relationship Queries profile, so the
`?withCount` family — the relationship-count request that adds a `meta.total` to a
named countable relationship object — is parsed and recognized **only** when a
client negotiates the profile's URI in the `Accept` `profile` parameter, and the
response advertises it like any applied profile. The bundle's count machinery
(`RelationCountBatcher`, the request-scoped count seam, the pushed-down/batched
provider counts) is unchanged: it reads `getCountedRelationships()`, which now
returns an empty set unless the profile was negotiated, so an un-negotiated
`?withCount` simply counts nothing and (under strict query-parameter validation)
is rejected as an unrecognized family. This is a breaking change for a bare
`?withCount`, taken pre-1.0; the conformance suites and the example app negotiate
the profile on every count read.
