# haddowg/json-api-symfony

[![CI](https://github.com/haddowg/json-api-symfony/actions/workflows/ci.yml/badge.svg)](https://github.com/haddowg/json-api-symfony/actions/workflows/ci.yml)

> **Part of the [jsonapi.rest](https://jsonapi.rest) suite** — a complete, spec-compliant
> JSON:API 1.1 stack for PHP: a framework-agnostic [core](https://github.com/haddowg/json-api),
> this **Symfony bundle**, a [Laravel package](https://github.com/haddowg/json-api-laravel), and
> a typed TypeScript client, bound together by one conformance-tested OpenAPI 3.1 contract.

A [Symfony](https://symfony.com/) bundle that makes
[`haddowg/json-api`](https://github.com/haddowg/json-api) idiomatic in a Symfony application:
register a JSON:API type as a service and get the standard
[JSON:API 1.1](https://jsonapi.org/format/1.1/) endpoint set — idiomatic routing, content
negotiation, spec-compliant error documents, constraint validation, and a reference Doctrine
data layer — with **no controller, no operation handler, and no serializer wired by hand**.

It is the Symfony twin of the [Laravel package](https://github.com/haddowg/json-api-laravel):
both build on the same framework-agnostic core and project a **byte-identical OpenAPI document**
for an identical domain, so a client generator consumes either backend unchanged.

## Requirements

- PHP 8.3, 8.4, or 8.5
- Symfony 6.4, 7.x, or 8.x

Doctrine, the validator, the opis linter, and strict email are **opt-in** (`suggest`
dependencies) — install one to enable that capability, leave it out and the path is simply
absent. See the [optional-dependency matrix](https://haddowg.github.io/json-api-symfony/configuration/).

## Installation

```bash
composer require haddowg/json-api-symfony
```

Composer pulls the core library and the PSR-7 bridge it needs. Registering the bundle and
importing its routes — the one step that surprises everyone, since the bundle mounts **no**
routes until you ask it to — is covered in
[install](https://haddowg.github.io/json-api-symfony/install/).

## Documentation

Full documentation is published at **[haddowg.github.io/json-api-symfony](https://haddowg.github.io/json-api-symfony/)**.
Start with [install](https://haddowg.github.io/json-api-symfony/install/) and
[getting started](https://haddowg.github.io/json-api-symfony/getting-started/), or browse the
[documentation index](https://haddowg.github.io/json-api-symfony/).

Core concepts (fields, relations, constraints, response value objects) live in the
[core documentation](https://haddowg.github.io/json-api/).

## Demo

`docker compose up` from [`examples/music-catalog-symfony`](examples/music-catalog-symfony) boots
the full music-catalog service — `curl http://localhost:8080/albums`, with interactive OpenAPI
docs (Swagger UI) at `http://localhost:8080/docs`. The
[getting-started guide](https://haddowg.github.io/json-api-symfony/getting-started/) walks the
same example.

## Licence

Released under the [MIT Licence](LICENSE).
