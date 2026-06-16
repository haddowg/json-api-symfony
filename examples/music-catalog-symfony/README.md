# Music Catalog — `haddowg/json-api-symfony` example app

A complete JSON:API 1.1 service built on **`haddowg/json-api-symfony`**, served
from a real **Symfony + Doctrine** application over an in-memory SQLite database.
It is the Symfony+Doctrine twin of core's in-memory
[`examples/music-catalog/`](https://github.com/haddowg/json-api/tree/main/examples/music-catalog)
— **same eight domains, same theme** — so you can hold the two example apps side
by side and see exactly what the framework integration adds.

This app is the **single source of truth** for the bundle docs: every doc snippet
is extracted from a CI-run `KernelTestCase`, so the docs cannot drift.

> This example is **not published to Packagist**. Its `composer.json` is a
> documentation artifact showing what a real integrating app requires (the bundle,
> `symfony/*` including `symfony/doctrine-bridge` for `UniqueEntity` and
> `symfony/intl` for the reference-data resource, and `doctrine/*`). It runs as
> part of the bundle's own test suite via the bundle's autoload-dev + PHPUnit
> wiring — you do not `composer install` it standalone.

## The eight domains

Seven are Doctrine-entity-backed `AbstractResource` types; one (`Chart`) is a
store-backed serialize-only type with no entity and no resource.

| Type | Backing | Highlights |
| --- | --- | --- |
| `artists` | `Artist` entity | singular `filter[slug]`, computed `trackCount`, `hasMany albums` |
| `albums` | `Album` entity | multi-server (default + `admin`), directional `CompareField`, `Map releaseInfo` (JSON column), default-include `artist`, `WhereHas tracks` |
| `tracks` | `Track` entity | **serializer override** (`TrackSerializer`), `storedAs` rename, `ArrayList genres`, `like` filter, `belongsToMany playlists` (`cannotReplace`) |
| `playlists` | `Playlist` entity | **hydrator override** (`PlaylistHydrator`), client-generated UUID id, derived `slug` |
| `users` | `User` entity | **admin-server-only**, `UniqueEntity` on `email`, write-only `password`, validation-composition trio |
| `favorites` | `Favorite` entity | **polymorphic to-one** `MorphTo favoritable` (Track\|Album\|Artist) |
| `libraries` | `Library` entity | **polymorphic to-many** `MorphToMany items` (custom provider) |
| `charts` | store-backed | standalone serializer, read-only (no entity, no resource) |

## What the Foundation phase ships

This phase is the integration backbone everything else builds on — it boots and is
gate-clean. It contains:

- **`src/Entity/`** — the seven Doctrine entities.
- **`src/Resource/`** — the seven `AbstractResource` service classes, complete (the
  field/relation/constraint DSL re-themed from core's example), with the
  multi-server attributes (`AlbumResource` on `['default','admin']`, `UserResource`
  on `admin`) and the override attributes (`TrackResource → TrackSerializer`,
  `PlaylistResource → PlaylistHydrator`).
- **`src/Serializer/TrackSerializer.php`** and **`src/Hydrator/PlaylistHydrator.php`**
  — the override witnesses, each with a bound constructor argument (proving DI
  resolution).
- **`config/`** — `bundles.php`, `services.yaml`, `packages/{framework,json_api,doctrine}.yaml`
  (the `admin` server lives under `json_api.servers`), and `routes/json_api.yaml`
  (the default `.` import plus the per-server `admin` import under `/admin`).
- **`src/DataFixtures/Seed.php`** — plain deterministic fixtures (not Foundry)
  seeding all seven entity types as a coherent object graph.
- **`tests/MusicCatalogKernelTestCase.php`** — boots the kernel, creates the schema,
  loads the seed; and **`tests/FoundationSmokeTest.php`** — the boot/`200` smoke
  proof.

The custom providers (`LibraryItemsProvider`, the priority-shadow witness, the
`countries` reference-data provider), the standalone `charts` serializer + provider,
the `PublishedAlbumsExtension` query scope, and the full per-page conformance suites
arrive in the **Build** phase.

## Running it

From the **bundle root** (`json-api-symfony/`):

```bash
composer test     # PHPUnit — includes the example suite
composer phpstan  # PHPStan level 9 over src + tests + examples
composer cs-check # PHP-CS-Fixer, PER-CS 2.0
```
