# Per-relation endpoint exposure is enforced in the handler

The relationship routes are parametric (one route per shape, `{relationship}` a
parameter), so suppression cannot live in routing — the `CrudOperationHandler`
enforces each relation's endpoint-exposure flags instead: a read to a
`withoutRelatedEndpoint()` / `withoutRelationshipEndpoint()` relation is a `404`
(reusing core's `RelationshipNotExists`, since the endpoint simply does not
exist for that relation), and a `POST` add to a `cannotAdd()` to-many is a `403`
(core's `AdditionProhibited`). Core already omits the convention link to a
suppressed endpoint (core ADR 0033), so a rendered self/related link never points
at a `404`.
