# The error coverage facts come from what the bundle enforces

Core offers an error code only on the operations whose runtime can raise it, and asks the adapter for the facts only it knows. The bundle answers each one from the code path that raises the code: `ACCESS_DENIED` and `AUTHENTICATION_REQUIRED` from the security expressions the subscriber evaluates and from the firewall and `access_control` rule Symfony matches for the operation's route, the detachment and linkage facts from the Doctrine mapping, the include safeguards from the serializer the server renders the type with. Where a fact depends on code the bundle can't read, such as a persister of the application's own or a route it can't find, it answers that the code can arise, because a missing code hurts a client more than a spare one.

## Considered options

- Answering `AUTHENTICATION_REQUIRED` from each operation's declared security requirement: a stateless authenticator rejects a bad token on a public route too, so only the firewall knows.
- Leaving a duplicate client id to the database: Doctrine answered a `500` where the Laravel package answers `409 CLIENT_GENERATED_ID_ALREADY_EXISTS`, so both reference persisters now look the id up before they write.

## Consequences

A rule matching on something a request built from the route can't carry, such as the client's IP, is judged against a request from `127.0.0.1`.
