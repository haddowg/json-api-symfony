# Installation and bundle registration

This page covers installing `haddowg/json-api-symfony`, registering the bundle,
and the one step that surprises everyone: **routes are not auto-mounted**. The
rest of the docs assume the steps here are done.

## Requirements

| Requirement | Constraint |
| --- | --- |
| PHP | `^8.3` (8.3 / 8.4 / 8.5) |
| Symfony | `^6.4 \|\| ^7.0 \|\| ^8.0` |
| `symfony/framework-bundle` | required — the bundle relies on its services |

The hard runtime dependencies beyond Symfony are the PSR-7 bridge the kernel
listeners use: `nyholm/psr7` (the PSR-7 implementation) and
`symfony/psr-http-message-bridge` (the PSR-7 ↔ HttpFoundation converter). Both are
direct `require` entries in the bundle's `composer.json`, so installing the bundle
pulls them in — you do not require them by hand.

## Install

```bash
composer require haddowg/json-api-symfony
```

Composer pulls the core library (`haddowg/json-api`) transitively, along with the
PSR-7 bridge described in the requirements above — you do not require them by hand.

## Register the bundle

The bundle ships no Flex recipe, so add it to `config/bundles.php` yourself. The
example app registers three bundles — FrameworkBundle (required), DoctrineBundle
(the reference data layer), and the bundle itself:

```php
// config/bundles.php
return [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
    Doctrine\Bundle\DoctrineBundle\DoctrineBundle::class => ['all' => true],
    haddowg\JsonApiBundle\JsonApiBundle::class => ['all' => true],
];
```

Source: [`config/bundles.php`](../examples/music-catalog-symfony/config/bundles.php).
If you are not using the Doctrine data layer, drop the `DoctrineBundle` line — the
bundle's Doctrine wiring removes itself when no entity is mapped (see
[doctrine](doctrine.md)).

The bundle class is `haddowg\JsonApiBundle\JsonApiBundle` (an `AbstractBundle`); its
configuration extension alias `json_api` is auto-derived from the bundle name, so
there is no `Configuration` or `Extension` class to register.

> [!NOTE]
> The bundle's own functional test kernels register bundles **imperatively** through
> `registerBundles()` (a `MicroKernelTrait` pattern) rather than `bundles.php` — a
> test-harness convenience, not the integration model. A real app uses
> `config/bundles.php` as above; the example app's
> [`MusicCatalogKernel`](../examples/music-catalog-symfony/src/MusicCatalogKernel.php)
> is a real app kernel that loads `bundles.php`, and is the model to copy. The
> imperative test-kernel pattern is documented on
> [multi-server-and-testing](multi-server-and-testing.md).

`symfony/validator`'s services are discovered automatically by FrameworkBundle once
the package is installed — there is nothing to register for it (see
[validation](validation.md)).

## Routes are a separate, required step

> [!WARNING]
> Registering the bundle creates **no endpoints**. The bundle does **not** auto-mount
> any routes — you must import its custom route type. Many readers stop after
> `bundles.php` and wonder why `GET /albums` 404s. This is the step.

Add a route import that uses the bundle's loader (`type: jsonapi`). The example app
imports the default server's routes from `config/routes/json_api.yaml`:

```yaml
# config/routes/json_api.yaml
json_api_default:
    resource: '.'
    type: jsonapi
```

Source:
[`config/routes/json_api.yaml`](../examples/music-catalog-symfony/config/routes/json_api.yaml).
Symfony's default config-routes loading picks this file up automatically; if your
app does not scan the `config/routes/` directory, import it explicitly from your
main routes config so the endpoints actually mount.
The `resource:` argument **names the server** whose routes to emit — not a path or glob
(the bundle emits one route per discovered type/operation from its compiled
descriptors). `resource: 'default'` is the self-describing form for the single-API case;
the bare `.` above is a back-compat alias for it. The full route set, the operation
gating, and the per-server import are covered on [routing](routing.md).

## Minimal `json_api` configuration

The configuration tree is tiny. For a single-API app, only `base_uri` matters — it
defines the implicit `default` server. Leave it empty (the default) and links are
absolute, built from the request's scheme and host; set it to pin a fixed canonical
host regardless of the request:

```yaml
# config/packages/json_api.yaml
json_api:
    base_uri: 'https://music.example'
```

Source:
[`config/packages/json_api.yaml`](../examples/music-catalog-symfony/config/packages/json_api.yaml)
(the example app sets a `version:` too, but only because it pins the explicit `'1.1'`
witness — `version:` defaults to `'1.1'`, so you set it only for a non-default JSON:API
version; most apps omit it).
The full config tree (`base_uri`, `version`, `max_include_depth`,
`strict_query_parameters`, `pagination`, `doctrine`, `schema_validation`,
`defaults`, and the `servers:` map) and the container parameters it surfaces are
documented on [configuration](configuration.md); what `base_uri`/`version`
configure on each core
[`Server`](https://github.com/haddowg/json-api/blob/main/docs/server.md) is core's.

## Optional dependencies

Most of the bundle's richer behaviour is opt-in through `suggest` dependencies.
Each is named once here; the full degradation matrix (what each enables and how the
bundle behaves without it) is on
[configuration](configuration.md).

| Package | Enables |
| --- | --- |
| `doctrine/orm` | the reference Doctrine data provider / persister ([doctrine](doctrine.md)) |
| `symfony/validator` | the constraint bridge that validates writes ([validation](validation.md)) |
| `symfony/doctrine-bridge` | the `UniqueEntity` entity-level rule ([validation](validation.md)) |
| `egulias/email-validator` | strict (RFC 5322) email validation |
| `opis/json-schema` | the optional structural document linter (`schema_validation`) |

## Next

You are installed and the bundle is registered. Continue with
[getting-started](getting-started.md) to build your first music-catalog endpoint
end to end, or jump to [routing](routing.md) for the route import in full.
