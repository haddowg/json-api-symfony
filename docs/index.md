# haddowg/json-api-symfony: spec-compliant JSON:API for Symfony

`haddowg/json-api-symfony` is a Symfony bundle that makes
[`haddowg/json-api`](https://github.com/haddowg/json-api) idiomatic in a Symfony
application. You register a JSON:API type as a service; the bundle discovers it by
autoconfiguration, mounts the standard endpoint set through a route loader, runs
the request lifecycle from kernel listeners, renders every failure on a JSON:API
route as a spec-compliant error document, and — when Doctrine is installed — fetches
and persists your entities through a reference data layer. No controller, no
operation handler, no serializer wired by hand.

## The bundle builds on core — you will read both doc sets

There are two libraries, with a clean split of responsibilities:

- **The core library owns the JSON:API model.** `AbstractResource`, the field and
  relation DSL, the constraint vocabulary, the response value objects (VOs), the
  document model, operations, content negotiation, the exception catalogue — all of that
  lives in core and is framework- and storage-agnostic. Start with the core
  [index](https://github.com/haddowg/json-api/blob/main/docs/index.md),
  [getting-started](https://github.com/haddowg/json-api/blob/main/docs/getting-started.md),
  and [concepts](https://github.com/haddowg/json-api/blob/main/docs/concepts.md) —
  they are the shared mental model every page in *these* docs assumes.
- **This bundle owns the Symfony integration.** Discovery and DI, the route loader,
  the kernel-listener lifecycle, route-scoped error rendering, the Doctrine data
  layer, the `DataProvider`/`DataPersister` service-provider interface (SPI), the
  Symfony Validator bridge, configuration, and multi-server wiring.

So these docs never re-explain a core concept — they link it. When a bundle page
touches `fields()`, it links core
[fields](https://github.com/haddowg/json-api/blob/main/docs/fields.md) and documents
only the Symfony affordance around it. The same `AbstractResource` you would
hand-register on a core `Server` is here discovered by autoconfiguration; the same
operation handler you would hand-write in core is here a single generic handler
driven by listeners; the same constraint VOs core *declares but never executes* are
here translated to Symfony Validator rules and actually enforced.

## Pre-1.0: expect breaking changes

This bundle and its core dependency are **co-evolving pre-1.0**. Breaking changes
land between `0.x` minors (release-please drives the changelog), and the public API
is not yet frozen. Pin a minor and read the changelog before you upgrade.

## Requirements

| Requirement | Version | Why |
| --- | --- | --- |
| PHP | 8.3, 8.4, or 8.5 | The bundle uses typed class constants (`public const string …`), which are 8.3+. 8.3 is a hard floor. |
| Symfony | 6.4 or 7.x | `symfony/framework-bundle`, `config`, `dependency-injection`, `http-foundation`, `http-kernel`, `routing`. |
| `nyholm/psr7` + `symfony/psr-http-message-bridge` | latest | Hard runtime deps. The lifecycle listeners convert the Symfony `Request` to PSR-7 to drive core, then bridge the PSR-7 response back to HttpFoundation. |

Everything else — Doctrine, the validator, strict email, the opis linter — is
**opt-in**, declared as `suggest`. See the optional-dependency matrix on
[configuration](configuration.md).

## Install caveat (stated once)

Core `haddowg/json-api` is **not yet published to Packagist**, so installing this
bundle is not yet a plain `composer require`. You add core as `dev-main` through a
global Composer path or VCS repository; once core ships v1 this collapses to a
normal `composer require haddowg/json-api-symfony` with a `^1.0` core pin. The full
recipe — and bundle registration — lives in [install](install.md). It is the one
place these docs cover it.

## A taste

A JSON:API type is a service. Extend `AbstractResource`, declare your `fields()`,
and map the backing entity with `#[AsJsonApiResource]`:

```php
<?php

declare(strict_types=1);

namespace App\Resource;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use App\Entity\Album;

#[AsJsonApiResource(entity: Album::class)]
final class AlbumResource extends AbstractResource
{
    public static string $type = 'albums';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title')->required()->sortable(),
            // …
        ];
    }
}
```

With `autoconfigure: true`, any class extending `AbstractResource` is auto-tagged —
no manual service tags. Set the base URI and import the route type:

```yaml
# config/packages/json_api.yaml
json_api:
    base_uri: 'https://music.example'

# config/routes/json_api.yaml — routes are NOT auto-mounted; this import is required
json_api:
    resource: '.'
    type: jsonapi
```

That is the whole integration. You now have `GET /albums`, `GET /albums/{id}`,
`POST /albums`, `PATCH /albums/{id}`, and `DELETE /albums/{id}`, each rendering a
JSON:API 1.1 document — collections, sparse fieldsets, sorting, filtering,
pagination, relationships, and validated writes — over your Doctrine entity, with
no controller and no handler. The snippet above is illustrative; the
copy-paste-runnable version is built step by step in
[getting-started](getting-started.md), and every snippet in these docs is lifted
from the CI-tested example app — the real
[`AlbumResource`](../examples/music-catalog-symfony/src/Resource/AlbumResource.php)
layers on a `Map`, a directional `CompareField`, relations, a default include, and
multi-server exposure.

## Optional capabilities

The bundle does the minimum out of the box and lets you opt into the rest. Each of
these is a `suggest` dependency — install it to enable the capability, leave it out
and that path is simply absent:

| Install | Enables |
| --- | --- |
| `doctrine/orm` | The reference Doctrine [data layer](doctrine.md) — zero-config fetch/persist for any `#[AsJsonApiResource(entity: …)]` type. |
| `symfony/validator` | The Symfony Validator [bridge](validation.md) — core's declared constraints become enforced `422` validation. |
| `egulias/email-validator` | Strict (RFC 5322) email validation; without it the email rule degrades to HTML5 mode. |
| `opis/json-schema` | The optional structural document linter (`json_api.schema_validation`). |

The full matrix, with the degradation behaviour when each is missing, is on
[configuration](configuration.md).

## Where to go next

These docs read in six arcs:

1. **Getting started** — [install](install.md), then build your first endpoint in
   [getting-started](getting-started.md).
2. **Wiring & discovery** — [resources](resources.md) (discovery and
   `#[AsJsonApiResource]`), [capability-composition](capability-composition.md)
   (serializer / hydrator / relations as independent capabilities), and
   [configuration](configuration.md).
3. **The request lifecycle** — [routing](routing.md), [lifecycle](lifecycle.md),
   [lifecycle-hooks](lifecycle-hooks.md) (author seams around each operation), and
   route-scoped [errors](errors.md).
4. **The data layer** — the [data-layer](data-layer.md) SPI, the
   [doctrine](doctrine.md) reference adapter, and
   [custom-data-providers](custom-data-providers.md).
5. **Validation** — the [validation](validation.md) bridge and the opis linter.
6. **Advanced & cross-cutting** — [relationships](relationships.md), custom
   [serializers and hydrators](custom-serializers-hydrators.md) (and handler
   decoration), [multi-server and testing](multi-server-and-testing.md),
   [security and deployment](security.md), and declarative
   [authorization](authorization.md) (per-resource `security:` expressions).

If you are new to JSON:API itself, read core's
[getting-started](https://github.com/haddowg/json-api/blob/main/docs/getting-started.md)
and [concepts](https://github.com/haddowg/json-api/blob/main/docs/concepts.md)
first — this bundle assumes that vocabulary throughout.

**Next:** [Installation and bundle registration →](install.md)
