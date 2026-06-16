# Singular-filter collapse in the CRUD handler

When the client applies a filter the resource declares
`Resource\Filter\SupportsSingular` singular (core ADR 0039), the generic
`CrudOperationHandler` collapses the collection fetch to a zero-to-one response:
it renders the **first** matched row as a single resource via
`DataResponse::fromResource()` — or `data: null` when nothing matched — instead of
`fromCollection()`/`fromPage()`. The collapse is detected by intersecting the
resource's declared filters with the keys the client actually sent
(`appliesSingularFilter()`), so it triggers only on an *applied* singular filter and
the bare collection is unaffected.

A singular response is not a collection, so the handler skips pagination entirely
(no window, no page meta), matching the JSON:API to-one shape. Both providers reach
it through the same handler arm: the in-memory and Doctrine providers each execute
the underlying `Where` filter as usual and return their (zero-or-one) rows; the
handler takes the first and renders it. Core needed no rendering change —
`DataResponse::fromResource(null)` already renders `data: null`. Asserted on both
providers by the singular cases in `ReadQueryConformanceTestCase`.
