# A `securityList` key gates the collection read at runtime

Declarative authorization had no hook for the collection read (`GET /{type}`): a
`securityRead` expression gated only the single read (`AfterFetchOne`), and the
documented stance was "collections aren't gated — use a query scope." But a blanket
"only these roles may list this resource at all" is a real need distinct from
row-level filtering, and the OpenAPI document could not opt a collection out of a
document-level default (the prior `security: false` mapped to the single read only).

A new sixth security key, **`securityList`**, gates the collection read:

- **Runtime.** The handler dispatches a new `BeforeFetchCollectionEvent` *before* the
  provider query; `ResourceSecuritySubscriber` evaluates `securityList` (an
  ExpressionLanguage string) with a **null** subject (a collection has no single
  object — use a role/attribute check) and throws `AccessDeniedException` (→ `403`, or
  `401` unauthenticated) when it fails. A denied caller never triggers the query — an
  all-or-nothing gate, distinct from row-level visibility (still a query-scope concern).
- **Documentation.** `securityList` classifies `FetchCollection` into the secured /
  public sets (`MetadataSource`), so the projector emits the collection operation's
  `security` + `401` (or `security: []` for `false`) from the same effective-security
  logic as every other operation (core ADR 0098). No core change — `FetchCollection`
  already flows through the projector's `securityFor()`.

`securityList` accepts the same `string|bool|null` as the other keys (an expression is
enforced + documented; `true`/`false` are documentation-only; `null` inherits). Per a
deliberate decision, the catch-all `security` **cascades** to the collection
(`forList() = list ?? default`) — so `security` is now the true default for *every*
operation. **This is a breaking change:** a resource that declared a `security`
catch-all now gates its collection too. A per-object default (`is_granted('EDIT',
object)`) cannot apply to a whole collection (the null subject denies), so such a
resource must set `securityList` explicitly (a role check, or `false`) — as the test
suite's `ownedWidgets` now does (`securityList: "is_granted('ROLE_USER')"`: any user
may list, only the owner may edit). The example `ArtistResource` uses
`securityList: false` (with `securityRead: false`) for a fully public catalogue under
the bearer default.
