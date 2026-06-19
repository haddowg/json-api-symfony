# Two `json-api:` CLI commands export the OpenAPI document and per-type JSON Schemas

The OpenAPI document and the standalone per-type JSON Schemas must be exportable
outside any HTTP exposure — for CI spec-diffing, publishing, and codegen (D6/D11/D13).
Two console commands do this, establishing the bundle's `json-api:` command namespace
(the bundle had no commands before):

- `json-api:openapi:export [--server=default] [--format=json|yaml] [--output=FILE]` —
  writes a server's OpenAPI 3.1 document to a file or stdout. `--format=yaml` requires
  `symfony/yaml` (a suggested dependency) and fails with a clear message when it is
  absent rather than emitting broken output.
- `json-api:json-schema:export [--server=default] [--type=…] [--output=DIR|FILE]` —
  writes a server's standalone per-type JSON Schema 2020-12 documents (the resource
  object, projected by the **same** core `SchemaProjector` the OpenAPI document uses, so
  the standalone artifact and the in-document component agree): one type to a file /
  stdout (`--type`), or every type to a directory (one `<type>.json` each), or — with no
  output path — a single stdout object keyed by type.

Both commands are **always registered** (independent of the `expose_in_prod` HTTP gate),
since a publishing/CI pipeline needs the export with no web exposure; this is why
`symfony/console` is a direct (not suggested) dependency. They write straight to the
output stream (no `SymfonyStyle` decoration) when emitting to stdout, so a piped or
redirected document is byte-clean.
