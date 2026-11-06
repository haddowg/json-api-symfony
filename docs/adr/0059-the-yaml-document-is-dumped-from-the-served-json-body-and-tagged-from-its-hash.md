# The YAML document is dumped from the served JSON body and tagged from its hash

`docs.yaml` is `OpenApiController` with a `format` route default, so the bundle's routes and an app's hand-declared ones share one build: the controller builds the JSON body as before, serve decorators and pruning included, then dumps that body decoded to objects. Dumping the value objects' arrays instead would turn an empty list such as `security: []` into a map, and a second YAML build path would drift from the JSON one. Its ETag is the JSON tag with the format hashed in, so a `304` costs what the JSON one does and never needs the YAML bytes, and the warmer stores no YAML: only an undecorated `200` could read it, and a docs fetch is rare and usually cached at the edge.

## Consequences

The export command and the `public_path` file dump YAML the same way. Dumping the arrays with `DUMP_EMPTY_ARRAY_AS_SEQUENCE` alone would only swap the bug, since the arrays can't tell an empty object from an empty list. Like the JSON route, a hand-declared YAML route isn't held to the expose gate; the controller only answers `404` while `yaml.enabled` is off. JSON ETags are unchanged.
