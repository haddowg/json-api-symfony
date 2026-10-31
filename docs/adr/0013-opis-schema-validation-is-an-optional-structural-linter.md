# Structural JSON Schema validation is an optional linter, separate from the Validator bridge

The Validator bridge (ADR 0012) is the semantic layer, rendered as `422`. Validating a write body against the JSON:API schema with `opis/json-schema` is a separate structural check, rendered as core's `400`, and is off by default behind `json_api.schema_validation`, because full-document schema validation is a dev/CI affordance not worth paying for on every production write. Enabling it without opis installed fails the container build rather than the first request.
