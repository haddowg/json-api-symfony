# A structured attribute's children validate implicitly, one level deep

The children of a `Map` or `Obj` attribute validate through a nested `Collection` that mirrors the top-level attributes (the same presence rules, pointers such as `/data/attributes/address/postcode`) without an explicit `Valid` marker, so they follow the same rules as flat attributes with no second opt-in. Rules whose applicable schema depends on the value (a `OneOf` variant, a `Shape`'s raw JSON Schema run by core's `SchemaValueValidator`, a cross-field `CompareField`) run in a document-level pass instead, with the same pointer semantics.

## Consequences

The cascade does not descend into a nested composite. `Shape` value validation runs whenever opis is installed, independently of `json_api.schema_validation`.
