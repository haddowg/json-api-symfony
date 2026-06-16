# Reject unrecognized query parameters by default

Until now an unrecognized top-level query-parameter family — a typo (`?filtr`),
an unsupported custom param, or a profile family addressed without negotiating the
profile — was **silently dropped**, so a client typo yielded a wrong-but-`200`
result with no signal that the query was ignored. We make the bundle **reject an
unrecognized family with a `400`** (`QUERY_PARAM_UNRECOGNIZED`,
`source.parameter` = the offending base name) by default, closing API-Platform gap
G20. A family is recognized when its base name is a reserved JSON:API family
(`include`/`fields`/`filter`/`sort`/`page`), a key the resolved primary resource
declares, the always-on `withCount`, a negotiated profile's keyword, or an
app-registered custom param.

The validation itself lives in **core** (core ADR 0059): `Server` assembles the
recognized set per request from the resolved resource's vocabulary plus the
negotiated profiles' keywords and runs the check up front (before dispatch), gated
on a `Server::withStrictQueryParameters(bool)` toggle that defaults to `true`. The
bundle's whole contribution is **wiring**: a `json_api.strict_query_parameters`
config key (default `true`) the `ServerFactory` threads onto every `Server`, and
the route-scoped `ExceptionListener` renders the resulting `400`. Because the
bundle already registers the Relationship Queries profile on every server (ADR
0053), its `relatedQuery`/`rQ` family is recognized automatically when the client
negotiates the profile — and now correctly `400`s when addressed *without*
negotiation, rather than being ignored.

**Default-on is a deliberate, spec-aligned behaviour change.** The spec *mandates*
a `400` for a query param that follows neither the reserved-family rules nor the
custom-param naming rules and that the server does not recognize, and *permits* a
`400` for a well-named custom param the server does not support — so strict-by-
default is compliant and partly mandated. The trade-off is churn: any existing
request that sent a stray family (notably `relatedQuery` without the profile
negotiated) now `400`s, fixed faithfully across the suites rather than by weakening
the check. `strict_query_parameters: false` restores the old silent-ignore
behaviour for apps mid-migration. This sits **above** core's always-on spec
baseline (the all-`a-z` custom-param naming check), which still rejects an
all-lowercase stray param like `?bogus` regardless of the toggle; the toggle
governs only the strict superset (a *well-named* unsupported param).
