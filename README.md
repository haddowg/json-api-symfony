# haddowg/json-api-symfony

A [Symfony](https://symfony.com/) bundle that integrates
[`haddowg/json-api`](https://github.com/haddowg/json-api) into a Symfony
application — idiomatic routing, content negotiation, error handling, constraint
validation, and a Doctrine data layer for spec-compliant
[JSON:API 1.1](https://jsonapi.org/format/1.1/) endpoints.

> [!WARNING]
> **Pre-alpha — under active development.** Built alongside `haddowg/json-api`
> (itself pre-1.0) to validate the library's integration surface before its 1.0.
> Nothing here is stable yet.

## What it does

- **Discovers** your JSON:API Resource services automatically (autoconfiguration,
  with an optional `#[AsJsonApiResource]` attribute for extras).
- **Routes** convention JSON:API URLs to operations via a route loader and a
  `Target` resolver — register a resource, get the standard endpoint set.
- **Runs the lifecycle** as Symfony kernel listeners (content negotiation, body
  parsing, response rendering, and spec-compliant error documents for *every*
  failure on a JSON:API route), dispatching through the library's `Server`.
- **Validates** create/update bodies by translating the library's constraint
  metadata into Symfony Validator rules, mapping violations to JSON:API `422`
  errors.
- **Persists** through a `DataProvider` / `DataPersister` SPI, with a reference
  Doctrine ORM implementation that gives zero-handler CRUD for mapped resources.

## Requirements

- PHP 8.3, 8.4, or 8.5
- Symfony 6.4 or 7.x

## Installation

`haddowg/json-api` is not yet on Packagist. During co-development, clone it as a
sibling directory (`../json-api`) — this repository's `composer.json` resolves it
through a path repository:

```
Sites/
├── json-api/            # the core library
└── json-api-symfony/    # this bundle
```

```bash
composer install
```

## Status & roadmap

This bundle is being implemented in vertical slices, each backed by Doctrine and
each surfacing (and fixing) any gaps in the core library before its 1.0. See the
design notes in [`CLAUDE.md`](CLAUDE.md).

## Licence

Released under the [MIT Licence](LICENSE).
