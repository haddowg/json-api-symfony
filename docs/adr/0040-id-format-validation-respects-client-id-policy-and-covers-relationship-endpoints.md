# Id format validation respects the client-id policy and covers relationship endpoints

Two review follow-ups to the id format validation the Symfony Validator bridge
executes (ADR 0039), both correcting an inconsistency in *which* ids get
format-checked.

**The owning-id format check now gates on client-id acceptance.**
`ResourceValidator::ownIdError()` validated a client-supplied `data.id` against the
owning resource's id format unconditionally — even for a type that *forbids* client
ids (the default). A forbidden type carrying a format (e.g. an `ulid()->generated()`
id) then returned two different statuses for the same rejected id: a malformed client
id `422`'d on the format here, while a well-formed one reached core and `403`'d
`ClientGeneratedIdNotSupported`. The spec rule is uniform — a forbidden type rejects
*any* supplied id with `403`, irrespective of its format — so `ownIdError()` now
returns `null` when the type does not accept a client id, letting core's `hydrateId`
throw the `403`. The policy is read through `IdEncoderResolver::allowsClientIdFor()`
(new), since `AbstractResource::idField()` is protected; the linkage-id check is
unaffected (a linkage references a *related* type whose own id it always carries).

**Linkage-id format validation now also runs on the relationship-mutation endpoints.**
It previously ran only on whole-resource create/update bodies, so an identical
malformed linkage id passed straight to the persister when sent to a dedicated
`PATCH`/`POST`/`DELETE …/relationships/{rel}` endpoint instead of `422`-ing against
the related type's id format. `CrudOperationHandler::mutateRelationship()` now
validates the parsed linkage through `ResourceValidator::validateRelationshipLinkage()`
(new) before the persister apply — the same related-type format check, resolved
polymorphically per member, pointing at the endpoint body (`/data/id` or
`/data/<n>/id`, via `JsonPointerBuilder::forRelationshipEndpointLinkageId()`). The two
linkage surfaces now agree. Both fixes carry Doctrine witnesses: a forbidden+format
type `403`s on any client id, and a malformed relationship-endpoint linkage `422`s at
`/data/id` while a well-formed one passes.
