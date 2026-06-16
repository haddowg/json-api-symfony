# A shipped JSON:API kernel browser is the test utility

Before this decision the functional suites hand-rolled `handle(path, method, body)`
→ `Response` and `decode(Response): array`, then hand-asserted everything:
`assertSame(201, $response->getStatusCode())`, `$response->headers->get('Location')`,
`$data['type'] ?? null`. Three holes fell out: status + content type + body were never
asserted **as a unit**; a `?sort` result had **no first-class order witness**; and an
exact-match (a leaked/extra attribute) passed **silently**. Core grew the
framework-agnostic assertion families to close them ({@see
\haddowg\JsonApi\Testing\JsonApiDocument} / `JsonApiErrors` carrying a plain-scalar
`ResponseMeta` envelope), but nothing in the bundle bridged an HttpFoundation response
to them.

**Decision.** Ship a public, supported `src/Testing/JsonApiBrowser` extending Symfony's
`KernelBrowser`, constructed directly from the booted kernel (`new
JsonApiBrowser($kernel)`) so it works under a plain `KernelTestCase` boot without
`WebTestCase`/`framework.test`-created clients. It:

- **Disables kernel reboot in its constructor.** This is the headline trap: a
  `KernelBrowser` reboots the kernel between requests by default, which would wipe an
  in-memory SQLite seed bound to the kernel's connection. `disableReboot()` keeps the
  one booted kernel across requests, matching the old `handle()` that reused
  `static::$kernel` — so a write-then-read in a single test sees the write.
- **Negotiates content automatically.** Every request defaults
  `Accept: application/vnd.api+json`; `post()`/`patch()`/`delete()` set
  `Content-Type: application/vnd.api+json` and JSON-encode a passed-in document array
  — exactly what `handle()` injected.
- **Preserves the `kernel.exception` path.** Requests route through
  `kernel->handle(catch: true)` (the `KernelBrowser` default), so the bundle's
  `ExceptionListener` renders a `400`/`404`/`422` as a JSON:API error document, which
  `getErrors()` then asserts over — verified through the browser, not a thrown
  exception.
- **Keeps the PHPUnit strict handler stack balanced.** Handling installs Symfony's
  error/exception handlers; the browser snapshots them on construction and pops back to
  that snapshot after each request (`doRequest()` restore), mirroring the base test
  case.
- **Bridges to the core families.** `getDocument()`/`getErrors()` decode
  `$response->getContent()` and feed the status + a flattened header map as a
  `ResponseMeta` (4th `meta:` arg) so the envelope assertions
  (`assertStatus`/`assertContentType`/`assertHeader`) work alongside the body ones.
  Chainable convenience helpers — `assertCreated` (201 + Location + content type),
  `assertNoContent`, `assertFetchedOne`, `assertFetchedMany`,
  `assertFetchedManyInOrder` (the `?sort` witness), `assertFetchedOneExact`,
  `assertFetchedManyExact`, `assertHasError*`/`assertErrorsExact`,
  `assertNoData`/`assertNoMeta`/`assertNoLink` — forward to those families.
- **Authenticates statelessly over a Bearer access token.**
  `actingAs(UserInterface|string $user)` resolves the user identifier and
  authenticates as that seeded user **statelessly** by setting
  `Authorization: Bearer <token>` on every subsequent request — the most common API
  auth scenario, and storage-agnostic (no session, no `loginUser()`, no
  `framework.test`). The scheme is routed through two protected, overridable seams so
  a consumer with a different stateless scheme overrides **one** method:
  `authenticateAs(string $identifier)` (default sets the Bearer header) and
  `tokenFor(string $identifier)` (default returns the identifier — a real app maps an
  opaque token to a user). The test apps' firewalls gain a stateless `access_token`
  authenticator backed by the existing `UserInterface` provider via a tiny
  `AccessTokenHandler` that resolves the bearer token straight to the seeded user.
- **Derives the expected resource from the model.** `expectResource(object $entity)`
  renders the entity through **its own** serializer — resolved from the container's
  JSON:API server, the same registry the read endpoint uses — and returns the resulting
  resource object for `assertFetchedOneExact()`. This is a model-aware convenience the
  framework-agnostic core could not provide; the entity's type is matched against each
  registered serializer's `getType()`.
- **Is extensible by subclassing.** The class is non-`final` and exposes its behaviour
  as protected, overridable seams so a consumer can customise without copy-paste: the
  auth scheme (`authenticateAs`/`tokenFor`, above), the per-request `$_SERVER` defaults
  / negotiation headers (`defaultRequestServer(bool $hasBody)`), and how a response
  becomes a document/errors object (`documentFor`/`errorsFor`).

**A trait for `WebTestCase` consumers.** A standard Symfony `WebTestCase` gets a
`JsonApiBrowser` from the normal client-creation flow via `InteractsWithJsonApi`
(`src/Testing`): `static::createClient()` (or the `jsonApiClient()` accessor) returns a
`JsonApiBrowser`, so `$client->actingAs($ada)->get('/playlists')->assertFetchedMany()`
works with stock ergonomics. The idiomatic swap is to redefine the `test.client`
service's *class* to `JsonApiBrowser` — its constructor mirrors `KernelBrowser`'s
exactly, so it is a drop-in. But this bundle's harness boots imperative
`MicroKernelTrait` test kernels (no shared `config/packages/test/` to carry the service
override), which makes a per-kernel service edit fragile; so the trait takes the
documented alternative (the one `JsonApiFunctionalTestCase::browser()` already uses) and
**overrides `createClient()`** to build the browser straight from the booted kernel. The
trait also snapshots/restores the global error/exception handlers (PHPUnit strict) and
boots `debug => false` by default (production-fidelity error meta + no stdout debug
logs).

**Dogfood: the suites authenticate over Bearer.** The security/auth suites
(`AuthorizationTest`, `PivotTest`, `LifecycleHooksTest` in the example;
`DoctrineResourceSecurityTest`/`InMemoryResourceSecurityTest` in the bundle) and a
representative read/write slice (`ReadQueryTest`, `WriteTest`) migrate off the old
`PHP_AUTH_USER`/`handle()` form onto `browser()`/`actingAs($user)`. With every auth
test on the Bearer token, **`http_basic` is dropped from all three firewalls** — the
final state is Bearer-only, the realistic example. One non-obvious wrinkle: the
example's seeded users are identified by email (`ada@example.com`), which is not a
strict RFC 6750 `b64token`, so Symfony's default header extractor silently drops it;
the example firewall therefore names a permissive `BearerTokenExtractor` that trims
the `Bearer ` prefix and passes the rest verbatim (a real app whose opaque tokens are
not `b64token`s supplies one exactly like it). A migrated suite must drive **one**
request mechanism per test — mixing `browser()` and the base `handle()` across an
error-rendering (4xx) request desyncs the two error/exception-handler baselines and
hangs the restore loop — so a partially-migrated suite converts its reads too.

This is **test-support code**: it changes no runtime/production path in either package.
`symfony/framework-bundle` and `symfony/browser-kit` move into `suggest` (already in
`require-dev`) since the shipped class in `src/` references them — the same
suggested-dependency pattern as the Symfony Validator bridge. The old
`handle()`/`decode()` stay additive on `JsonApiFunctionalTestCase`, which gains a lazy
`browser()`; suites migrate incrementally.

**Consequences.** The browser is the place future testing affordances hang off (a
`?sort` order witness, exact-match, `expectResource`) and the documented entry point for
consumers writing JSON:API functional tests.
