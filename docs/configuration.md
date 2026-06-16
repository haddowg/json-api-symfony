# Configuration reference (`json_api:`) and optional dependencies

The bundle's configuration tree is intentionally tiny. Most of what makes a
JSON:API type work — discovery, routing, the data layer, validation — is wired by
**service tags and autoconfiguration**, not configuration keys (see
[capability composition](capability-composition.md) and [the data layer](data-layer.md)).
What `json_api:` configures is the small set of values that can't fall out of
discovery: the API's `base_uri` and `version`, an optional structural linter
toggle, and — for the multi-API case — additional named servers.

There is **no `Configuration.php` and no `Extension` class**. The tree is declared
inline in the bundle's `configure()` (an `AbstractBundle`), and the extension alias
`json_api` is auto-derived from the bundle name. The whole tree lives in
[`JsonApiBundle::configure()`](../src/JsonApiBundle.php).

## The config tree

Four keys, all optional:

```yaml
# config/packages/json_api.yaml
json_api:
    base_uri: 'https://music.example'
    version: '1.1'
    servers:
        admin:
            base_uri: 'https://admin.music.example'
```

— [`config/packages/json_api.yaml`](../examples/music-catalog-symfony/config/packages/json_api.yaml)
(the example app's real config; the `admin` server is the [multi-server](multi-server-and-testing.md)
witness).

| Key | Type | Default | What it does |
| --- | --- | --- | --- |
| `base_uri` | scalar | `''` | The absolute base URI the implicit `default` server prepends to every generated link. |
| `version` | scalar | `'1.1'` | The JSON:API version the implicit `default` server advertises. |
| `schema_validation` | bool | `false` | Registers the optional opis structural linter. Enabling it without `opis/json-schema` **fails the build**. |
| `servers` | map | `[]` | Additional **named** servers, keyed by name (ADR 0034). |

`base_uri` and `version` configure the implicit `default` server — link core
[server.md](https://github.com/haddowg/json-api/blob/main/docs/server.md) for what
a core `Server` does with them. A single-API app sets just `base_uri` (and usually
nothing else) and never touches `servers:`.

### Container parameters

Two of the keys surface as container parameters you can read elsewhere:

| Parameter | Source | Value |
| --- | --- | --- |
| `haddowg_json_api.base_uri` | `base_uri` | the configured (or empty) base URI |
| `haddowg_json_api.version` | `version` | the configured (or `'1.1'`) version |
| `haddowg_json_api.servers` | derived | the list of all server names, e.g. `['default', 'admin']` |

`haddowg_json_api.servers` is the resolved name list — always including the implicit
`default` — that the compiler pass reads to validate resource-to-server assignments
and bucket each type onto the right server.

### `schema_validation`

`schema_validation` is an optional dev/CI **structural** linter, distinct from the
always-relevant semantic validation. When `true`, the bundle wires core's
`DocumentValidator` + `VendoredSchemaProvider` so write bodies are checked against
the JSON:API JSON Schema before they reach the handler (a `400` on a malformed
document) — link core
[schema-validation.md](https://github.com/haddowg/json-api/blob/main/docs/schema-validation.md)
for what the linter checks. It is off by default and is **not** a substitute for the
[Symfony Validator bridge](validation.md), which validates *values* against your
declared constraints (a `422`).

`opis/json-schema` is a `suggest` dependency, so enabling `schema_validation`
without it is a wiring mistake the bundle catches at container-build time:

```
json_api.schema_validation is enabled but opis/json-schema is not installed;
require opis/json-schema (dev/CI) to use the structural document linter.
```

You can toggle it per-environment by layering a partial override — the example app's
[`SchemaValidationKernel`](../examples/music-catalog-symfony/tests/SchemaValidationKernel.php)
does exactly this, merging `['schema_validation' => true]` over the shipped config so
`base_uri`/`servers` stay unchanged.

## Named servers (`json_api.servers`)

The architecture is N-server-capable but single-server optimized (ADR 0034). The
top-level `base_uri`/`version` define the implicit **`default`** server, so most apps
need no `servers:` block at all. When you genuinely run more than one API surface —
say a public catalog and an internal admin API with a different base URI — declare the
extra surfaces under `servers:`:

```yaml
json_api:
    base_uri: 'https://music.example'
    servers:
        admin:
            base_uri: 'https://admin.music.example'
```

Each named server **inherits the top-level value when its own is omitted** — the
`admin` server above declares only `base_uri` and inherits `version: '1.1'`. Every
declared server (including `default`) gets one `ServerFactory`, registered under the
id `haddowg.json_api.server_factory.<name>`.

**Reserved name.** A named server may **not** be literally `default` — that name
belongs to the implicit top-level server. Declaring it is a build-time
`LogicException`:

```
The JSON:API server name "default" is reserved for the implicit server defined by
the top-level base_uri/version; declare other servers under different names.
```

Configuration only *declares* servers. The rest of the multi-server story lives on
three other pages, cross-linked so they don't drift:

- **Assignment** — which types join which server — is the `server:` argument on the
  resource attribute (`#[AsJsonApiResource(server: 'admin')]`, or a list for a shared
  type). See [resources](resources.md).
- **Route mounting** — one per-server import per surface (`resource: admin`, under a
  `prefix:`) — lives in your `routes/json_api.yaml`. See [routing](routing.md).
- **End-to-end resolution** — how a request reaches its own server's `ServerFactory`
  and renders that server's `base_uri` in links — is on
  [multi-server and testing](multi-server-and-testing.md).

## Optional dependencies

The bundle's hard runtime dependencies are minimal (see [installation](install.md)).
Everything beyond the read/write core is opt-in via a `suggest` dependency. Each one
**degrades gracefully when absent — but the degradation differs**, and two of them
are silent, so read this table before assuming a capability is active.

| Package | Enables | When absent |
| --- | --- | --- |
| `doctrine/orm` | The reference Doctrine provider/persister ([doctrine](doctrine.md)) | No Doctrine path; `#[AsJsonApiResource(entity:)]` mappings are inert. Provide your own [data provider](custom-data-providers.md). |
| `symfony/validator` | The constraint bridge ([validation](validation.md)) | **Writes run unvalidated, silently** — declared constraints are not enforced. |
| `symfony/doctrine-bridge` | The `UniqueEntity` entity-level rule | `UniqueEntity` cannot be translated. Usually present transitively via `doctrine/doctrine-bundle`. |
| `egulias/email-validator` | Strict (RFC 5322) email validation (`EmailFormat(strict)`) | **Strict silently degrades** to Symfony's HTML5 email mode. |
| `opis/json-schema` | The structural document linter (`schema_validation`) | Enabling `schema_validation: true` without it **fails the build**. |
| `symfony/intl` | Sourcing a reference-data type (e.g. `countries`) from the ICU dataset | The example app's `countries` type — a standalone `CountrySerializer` + `CountryProvider`, no entity — has no data source. |

The two **silent** degradations are the ones to internalise: without
`symfony/validator`, `CrudOperationHandler`'s validator resolves to null and writes
are accepted without checking your constraints; and `EmailFormat(strict)` quietly
falls back to HTML5 validation without `egulias/email-validator`. Neither raises an
error — see [validation](validation.md) for the full bridge behaviour.

`symfony/doctrine-bridge` is the subtle one: it ships Symfony's `UniqueEntity`
constraint and validator that the bundle's `UniqueEntity` VO translates to, and it is
typically only present *transitively* through `doctrine/doctrine-bundle`. The bundle
lists it in its own [`composer.json`](../composer.json) `suggest` so the requirement
is discoverable; the example app depends on it directly. See the `UniqueEntity`
section of [validation](validation.md).

These `suggest` entries are declared in the bundle's
[`composer.json`](../composer.json) and mirrored in the
[example app's `composer.json`](../examples/music-catalog-symfony/composer.json).

## Why this page is short

Almost nothing in the bundle is configured through `json_api:`. A resource is
discovered because its service is tagged by autoconfiguration; a custom data provider
shadows Doctrine because it's tagged at a higher priority; a constraint translator
registers because it implements an autoconfigured interface. The tag/priority model —
not config — is how you compose and override behaviour:

- The discovery and capability tags (`RESOURCE_TAG`, `SERIALIZER_TAG`, …) →
  [capability composition](capability-composition.md).
- The data-layer tags (`DATA_PROVIDER_TAG`, `DATA_PERSISTER_TAG`,
  `DOCTRINE_EXTENSION_TAG`) and their priority/first-match resolution →
  [the data layer](data-layer.md) and [custom data providers](custom-data-providers.md).
- The validation tag (`CONSTRAINT_TRANSLATOR_TAG`) → [validation](validation.md).

If you find yourself looking for a config key to turn something on, it's almost
certainly a tag instead.

## Next / See also

- [Resources and `#[AsJsonApiResource]`](resources.md) — including the `server:`
  assignment argument.
- [Routing](routing.md) — the per-server route import and the operation allow-list.
- [Multi-server and testing](multi-server-and-testing.md) — end-to-end server
  resolution.
- [Validation](validation.md) — what `symfony/validator` (and the optional linter)
  buys you.
- Core [server.md](https://github.com/haddowg/json-api/blob/main/docs/server.md) and
  [schema-validation.md](https://github.com/haddowg/json-api/blob/main/docs/schema-validation.md).
