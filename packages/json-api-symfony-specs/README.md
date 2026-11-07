# @haddowg/json-api-symfony-specs

The documents [haddowg/json-api-symfony](https://github.com/haddowg/json-api-symfony)
projects for its music-catalog example app, published with each release so nothing has to
commit a copy:

- `music-catalog.openapi.json` and `music-catalog-admin.openapi.json`: the OpenAPI 3.1
  document of the `default` and `admin` servers, as `json-api:openapi:export` writes it.
- `music-catalog.schemas.json` and `music-catalog-admin.schemas.json`: the same servers'
  JSON Schema 2020-12 documents, one object keyed by type, as `json-api:json-schema:export`
  writes it.
- `versions.json`: the `haddowg/json-api` and `haddowg/json-api-symfony` versions that
  projected them, so a consumer can check it compares against documents from the same core
  release.
- `conformance/identified-morph-many/{plain,hidden}/*.json`: the response documents the
  bundle's identified `MorphToMany` conformance suite asserts against, which an adapter's
  own suite must match byte for byte.

The package version is the json-api-symfony release that projected them. A pull request
publishes `0.0.0-pr.<number>.<sha>` under the dist-tag `pr-<number>`, deleted when the pull
request closes.

Install it from GitHub Packages, with the scope mapped in `.npmrc` and a token that can read
packages:

```
@haddowg:registry=https://npm.pkg.github.com
```
