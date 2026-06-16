# haddowg/json-api-symfony

A [Symfony](https://symfony.com/) bundle that integrates
[`haddowg/json-api`](https://github.com/haddowg/json-api) into a Symfony
application. Register a JSON:API type as a service and you get the standard
[JSON:API 1.1](https://jsonapi.org/format/1.1/) endpoint set — idiomatic routing,
content negotiation, spec-compliant error documents, constraint validation, and a
reference Doctrine data layer — with **no controller, no operation handler, and no
serializer wired by hand**.

> [!NOTE]
> **Pre-1.0, feature-complete, API not yet frozen.** Built alongside
> `haddowg/json-api` (itself pre-1.0) to validate the library's integration surface
> before its 1.0. The feature set below is complete and CI-tested; breaking changes
> may still land between `0.x` minors (release-please drives the changelog). Pin a
> minor and read the changelog before you upgrade.

## What it does

- **Discovers** your JSON:API Resource services automatically (autoconfiguration,
  with an optional `#[AsJsonApiResource]` attribute for extras).
- **Routes** convention JSON:API URLs to operations via a route loader — register a
  resource, get the standard endpoint set.
- **Runs the lifecycle** as Symfony kernel listeners (content negotiation, body
  parsing, response rendering, and spec-compliant error documents for *every*
  failure on a JSON:API route), dispatching through the library's `Server`.
- **Validates** create/update bodies by translating the library's constraint
  metadata into Symfony Validator rules, mapping violations to JSON:API `422`.
- **Persists** through a `DataProvider` / `DataPersister` SPI, with a reference
  Doctrine ORM implementation that gives zero-handler CRUD for mapped resources.

Beyond convention CRUD it ships the capabilities a real JSON:API service needs:

- **Relationships** — linkage and `self`/`related` `links`, related and relationship
  read **and** mutation endpoints, compound `?include`, and `?withCount`.
- **Pivot fields & filters** — `belongsToMany` over an association entity, rendering
  join columns as `meta.pivot` with author-declared pivot `?filter`/`?sort`.
- **The Relationship Queries profile** — order and narrow a relationship from the
  primary request (`relatedQuery[rel][sort]`/`[filter]`).
- **Constrained-existence filtering** — `WhereThrough` dotted-path traversal
  (`filter[author.name]=Smith`) as a correlated `EXISTS`.
- **Lifecycle hooks** — per-operation author seams as resource methods or event
  subscribers.
- **Declarative authorization** — per-resource Symfony Security `security:`
  expressions, denying with `403`/`401` before any persistence.
- **Custom & encoded resource ids** — client natural keys, app-minted UUID/ULID, or
  an opaque encoded id over a store-assigned integer.
- **Write-only attributes**, **cursor (keyset) pagination**, **strict query
  parameters**, **declarative cache & deprecation headers**, **multi-server**, and a
  JSON:API-aware **functional-testing** utility.

## Requirements

- PHP 8.3, 8.4, or 8.5
- Symfony 6.4 or 7.x

Doctrine, the validator, the opis linter and strict email are **opt-in** (`suggest`
dependencies) — install one to enable that capability, leave it out and the path is
simply absent. See the [optional-dependency matrix](https://haddowg.github.io/json-api-symfony/configuration/).

## Installation

`haddowg/json-api` is not yet on Packagist, so this bundle requires it as
`dev-main`. For local development, clone it as a sibling checkout (kept on its
`main` branch) and register it as a **global** Composer path repository — that
keeps the committed `composer.json` clean while symlinking your local core:

```
Sites/
├── json-api/            # the core library (checked out on `main`)
└── json-api-symfony/    # this bundle
```

```bash
composer config -g repositories.haddowg-json-api \
  '{"type":"path","url":"/absolute/path/to/json-api","options":{"symlink":true}}'
composer install
```

CI resolves `dev-main` straight from GitHub (a VCS repository) with no extra
checkout. Once core is published to Packagist this becomes a normal `composer
require haddowg/json-api-symfony` with a `^1.0` core pin. The full recipe — and
registering the bundle — is in [docs/install.md](https://haddowg.github.io/json-api-symfony/install/).

## Quickstart

A JSON:API type is a service. Extend `AbstractResource`, declare your `fields()`,
and map the backing Doctrine entity with `#[AsJsonApiResource]`:

```php
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
        ];
    }
}
```

With `autoconfigure: true`, any class extending `AbstractResource` is auto-tagged.
Set the base URI and import the route type (routes are **not** auto-mounted):

```yaml
# config/packages/json_api.yaml
json_api:
    base_uri: 'https://music.example'
```

```yaml
# config/routes/json_api.yaml — this import is required
json_api:
    resource: '.'
    type: jsonapi
```

That is the whole integration. You now have `GET /albums`, `GET /albums/{id}`,
`POST /albums`, `PATCH /albums/{id}`, and `DELETE /albums/{id}`, each rendering a
JSON:API 1.1 document over your entity — collections, sparse fieldsets, sorting,
filtering, pagination, relationships, and validated writes — with no controller and
no handler. The step-by-step build is in
[docs/getting-started.md](https://haddowg.github.io/json-api-symfony/getting-started/).

## Run the example live

The [`examples/music-catalog-symfony`](examples/music-catalog-symfony) app is a
complete Symfony + Doctrine JSON:API service (twelve domains, every feature above)
and the single source of truth for the docs — every doc snippet is extracted from
its CI-run tests. It ships a [FrankenPHP](https://frankenphp.dev/) container, so you
can boot the whole catalogue (seeded SQLite) and `curl` it:

```bash
docker compose up   # from examples/music-catalog-symfony/
curl -H 'Accept: application/vnd.api+json' http://localhost:8080/albums
```

It then serves on **http://localhost:8080**.

## Documentation

The full documentation is published at **[haddowg.github.io/json-api-symfony](https://haddowg.github.io/json-api-symfony/)**.
Start at the [documentation index](https://haddowg.github.io/json-api-symfony/), then:

- [install](https://haddowg.github.io/json-api-symfony/install/) — the `dev-main` install recipe and bundle registration.
- [getting-started](https://haddowg.github.io/json-api-symfony/getting-started/) — build your first endpoint end to end.
- [resources](https://haddowg.github.io/json-api-symfony/resources/) — discovery, `#[AsJsonApiResource]`, and id strategies.
- [routing](https://haddowg.github.io/json-api-symfony/routing/) and [lifecycle](https://haddowg.github.io/json-api-symfony/lifecycle/) — the request flow.
- [doctrine](https://haddowg.github.io/json-api-symfony/doctrine/) — the reference data layer (filters, sorts, related
  collections), with [custom-data-providers](https://haddowg.github.io/json-api-symfony/custom-data-providers/) and
  [pagination](https://haddowg.github.io/json-api-symfony/pagination/).
- [relationships](https://haddowg.github.io/json-api-symfony/relationships/) — relations, `?include`, `?withCount`,
  pivot data, and the relationship-queries profile.
- [validation](https://haddowg.github.io/json-api-symfony/validation/) — the Symfony Validator bridge.
- [lifecycle-hooks](https://haddowg.github.io/json-api-symfony/lifecycle-hooks/) and
  [authorization](https://haddowg.github.io/json-api-symfony/authorization/) — author seams and per-resource `security:`.
- [multi-server and testing](https://haddowg.github.io/json-api-symfony/multi-server-and-testing/),
  [configuration](https://haddowg.github.io/json-api-symfony/configuration/), [errors](https://haddowg.github.io/json-api-symfony/errors/), and
  [security](https://haddowg.github.io/json-api-symfony/security/).

The bundle builds on the core library's docs — when a page touches a core concept
(`fields()`, the constraint vocabulary, the response value objects) it links the
[core docs](https://github.com/haddowg/json-api/blob/main/docs/index.md) rather than
re-explaining it.

## Licence

Released under the [MIT Licence](LICENSE).
