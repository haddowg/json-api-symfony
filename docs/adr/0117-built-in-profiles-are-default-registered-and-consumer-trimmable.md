# Built-in JSON:API profiles are default-registered via `json_api.profiles` and consumer-trimmable

- **Status:** accepted

Core made the OpenAPI projection registration-aware (core ADR 0131): the projector
reads the server's registered profile set (`ServerMetadataInterface::profiles()`) and
gates profile-derived output on it — the `jsonapi.profile` enum, the Countable
`?withCount` parameter, the Relationship Queries `relatedQuery` parameter, and the
cursor page-schema profile marker are advertised only for a **registered** profile.
The bundle previously hardcoded two `->withProfile(new …())` calls in `ServerFactory`
(Relationship Queries + Countable, but **not** cursor) and had no consumer knob.

We replace that with a data-driven registration from a new `json_api.profiles` config
key — a list of `ProfileInterface` class-strings — defaulting to
`ServerFactory::DEFAULT_PROFILES`: the three built-ins
(`CursorPaginationProfile`, `CountableProfile`, `RelationshipQueriesProfile`) in that
**canonical order**. `ServerFactory` instantiates each and registers it in order;
`MetadataSource` reads the live registry off the built `Server`
(`$server->profiles()->all()` → each `uri()`) into `ServerMetadata::profiles()`, so the
generated document reflects exactly what the server recognizes — never a hardcoded
list. A consumer trims an entry to stop recognizing and advertising that profile (its
OpenAPI parameters disappear with it) or appends a class to recognize a custom one; an
invalid entry fails the container build with a clear message.

**Why.** Registration is the single source of truth for what the server honours — the
runtime parses a profile's opt-in query family only under negotiation, and only a
registered profile can be negotiated — so advertising a profile-gated parameter the
server did not register would document a request that always `400`s. Making the set
config-driven (rather than hardcoded) lets an application opt profiles in or out, and
folding cursor into the default closes a gap: the bundle now registers the
cursor-pagination profile, so a cursor response advertises it (Content-Type `profile`
param + `jsonapi.profile`) and the generated document's `jsonapi.profile` enum lists it
— the canonical `CursorConformanceTestCase` suite is the witness (its shared page
helper now asserts the advertised profile URI, while a count-based page selected from
the same menu asserts its absence). The canonical **order** is significant: it is the
byte-order the `jsonapi.profile` enum is generated in, so it is fixed identically here
and in the Laravel adapter for cross-adapter byte-parity. `MetadataSourceTest` and
`ServerFactoryProfilesTest` witness the default set, the registration order, and that
trimming `json_api.profiles` drops a profile's registration, its enum entry, and its
`relatedQuery`/`withCount` advertisement while the surviving profiles stay.
