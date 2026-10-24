# A declared security expression fails the build when nothing can evaluate it

A security expression on a resource, relation or custom action used to be skipped when symfony/security-core, symfony/expression-language or a firewall was missing, so the operation ran ungated with no signal. A declaration that can't be enforced now fails the build: `ResourceSecurityPass` checks resource and action expressions at compile time, where it can see both the declarations and whether `security.authorization_checker` is defined, and the `ServableResourceWarmer` checks relation expressions at warm-up, because they live in `fields()` and a compiler pass can't read them. The documentation-only `true`/`false` values need none of these packages, so the registry is always registered and they still build and project into the OpenAPI document.

## Consequences

The authorization checker is still injected optionally, so an app that declares no expression needs no firewall. If the subscriber meets an expression with no checker at runtime anyway, it throws and the request fails with a `500` rather than being let through.
