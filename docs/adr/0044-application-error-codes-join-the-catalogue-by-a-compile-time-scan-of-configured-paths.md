# Application error codes join the catalogue by a compile-time scan of configured paths only

An exception is not a service, so an application's described errors are found by a compiler pass walking `json_api.error_codes.paths` (plus tagged `ErrorCatalogSourceInterface` services), resolved when the container builds and rebuilt when a file changes. The default is no paths: an unscoped walk would publish any described error that happens to exist, such as a test fixture, as API contract, a defect that shows up in clients' generated code rather than in CI.

## Consequences

Contributed codes are documented on every server. An exception has no server affinity, and a per-server split would risk byte parity with the Laravel package.
