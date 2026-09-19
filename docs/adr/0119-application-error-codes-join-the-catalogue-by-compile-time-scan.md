# Application error codes join the OpenAPI catalogue by compile-time scan

Core's projected error catalogue is now assembled from contributed
`ErrorCatalogSourceInterface`s rather than a fixed roster, offered through the
`ContributesErrorCodes` server-metadata seam (core ADR 0136). An exception is not a
service, so the bundle cannot reach an application's described errors by
autoconfiguration the way it reaches resources. An `ErrorCatalogPass` therefore walks
the directories named in `json_api.error_codes.paths` and folds what it finds into one
`ClassListErrorSource`, and any tagged `ErrorCatalogSourceInterface` service
(autoconfigured onto `haddowg.json_api.error_source`) joins it for a class no scan
reaches.

The walk runs at **container build time**, not per request: the compiled container
carries the resolved class list and a `DirectoryResource` per path rebuilds it when a
file changes. This mirrors `ResourceLocatorPass` — discovery is a compile concern here,
and the catalogue is read while projecting a document, which is a warmed or exported
artefact rather than a request path.

Discovery is scoped to the configured paths and the default is none. An unscoped walk
of the project would publish whatever described error happened to exist — a test
fixture, a scratch class — as part of the API's contract, which is a defect that shows
up in a client's generated code rather than in CI.

## Consequences

Contributed codes are documented on **every** declared server. The core seam is
per-server, and the bundle could have scoped the contribution with a `server` tag
attribute the way `#[AsJsonApiResource]` does — but an exception class carries no server
affinity, and the Laravel package's discovery has no server dimension for errors at all,
so a per-server split here would be byte-parity risk bought with nothing.
