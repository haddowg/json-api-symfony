# Installation and bundle registration

This page covers installing `haddowg/json-api-symfony`, registering the bundle,
and the one step that surprises everyone: **routes are not auto-mounted**. The
rest of the docs assume the steps here are done.

> [!IMPORTANT]
> This is **not yet a normal `composer require`**. The core library
> [`haddowg/json-api`](https://github.com/haddowg/json-api/blob/main/docs/index.md)
> is not on Packagist yet, so it is required as `dev-main` and you must add a
> Composer repository that resolves it. This caveat — and only this caveat — lives
> here and in [index](index.md); every other page assumes the bundle is installed.
> At core v1 the whole dance below collapses to a plain
> `composer require haddowg/json-api-symfony` with a `^1.0` core pin and no
> repository stanza.

## Requirements

| Requirement | Constraint |
| --- | --- |
| PHP | `^8.3` (8.3 / 8.4 / 8.5) |
| Symfony | `^6.4 \|\| ^7.0` |
| `symfony/framework-bundle` | required — the bundle relies on its services |

The hard runtime dependencies beyond Symfony are the PSR-7 bridge the kernel
listeners use: `nyholm/psr7` (the PSR-7 implementation) and
`symfony/psr-http-message-bridge` (the PSR-7 ↔ HttpFoundation converter). Both are
direct `require` entries in the bundle's `composer.json`, so installing the bundle
pulls them in — you do not require them by hand.

## Resolving the core dependency

Pick one of the two recipes depending on whether you have a local sibling checkout
of core.

### Local development — a path repository

If you have core checked out as a sibling (e.g. `../json-api`), register a
**global** Composer path repository that symlinks it:

```bash
composer config -g repositories.haddowg-json-api \
  '{"type":"path","url":"/abs/path/to/json-api","options":{"symlink":true}}'
```

A path repository reports the package version as `dev-<branch>` of whatever branch
the checkout is on, and the bundle requires exactly `dev-main` — so **keep the core
checkout on its `main` branch**, or the constraint will not be satisfied. Because
the repository is global, it is not committed anywhere and does not shadow CI's
resolution.

### CI / no sibling checkout — a VCS repository

Without a local checkout, point Composer at the core GitHub repository instead so
`dev-main` resolves from source:

```bash
composer config -g repositories.haddowg-json-api \
  '{"type":"vcs","url":"https://github.com/haddowg/json-api"}'
```

### Then require the bundle

With a repository in place, require the bundle as usual:

```bash
composer require haddowg/json-api-symfony
```

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
The `resource:` argument is **not** a path or glob — the bundle emits one route per
discovered type/operation from its compiled descriptors, and `resource:` names the
*server* whose routes to emit (`.` is the implicit `default` server). The full route
set, the operation gating, and the per-server import are covered on
[routing](routing.md).

## Minimal `json_api` configuration

The configuration tree is tiny. For a single-API app, only `base_uri` matters — it
defines the implicit `default` server (and the absolute self-links in rendered
documents). The example app:

```yaml
# config/packages/json_api.yaml
json_api:
    base_uri: 'https://music.example'
    version: '1.1'
```

Source:
[`config/packages/json_api.yaml`](../examples/music-catalog-symfony/config/packages/json_api.yaml).
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
end to end, or jump to [routing](routing.md) for the route import in full. For why
the install looks like this, see core's
[not-on-Packagist note](https://github.com/haddowg/json-api/blob/main/docs/index.md).
