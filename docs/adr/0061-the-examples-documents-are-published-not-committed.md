# The example's documents are published, not committed

The music-catalog example's OpenAPI and JSON-Schema documents are what the code generators test against and what the Laravel adapter's byte-compat check compares with, and every copy committed downstream drifted from what the bundle actually projects. So `bin/export-specs.php` exports them in CI and they are published as `@haddowg/json-api-symfony-specs` on GitHub Packages, the way core publishes its contract witness: each release under its version, and each pull request from this repository as `0.0.0-pr.<n>.<sha>`, deleted when it closes, with a `versions.json` naming the `haddowg/json-api` and bundle versions that projected them so a consumer can tell it is comparing against the same core release. The reviewable diff a committed copy gave comes from a CI job that diffs a pull request's documents against its base's and fails until a `generated-output` label says someone read it.

## Considered options

- npmjs: anonymous installs, but deleting a version is restricted after 72 hours, and publishing would need a long-lived token or trusted publishing set up per package. GitHub Packages publishes and deletes with the workflow's own token; the cost is that installing needs a token even for a public package.
