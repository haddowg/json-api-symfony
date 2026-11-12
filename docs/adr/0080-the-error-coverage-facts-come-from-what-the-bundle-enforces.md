# The error coverage facts come from what the bundle enforces

Core offers an error code only on the operations whose runtime can raise it, and asks the adapter for the facts only it knows. The bundle answers each one from the code path that raises the code: `ACCESS_DENIED` and `AUTHENTICATION_REQUIRED` from the security expressions the subscriber evaluates and from the firewall and `access_control` rule Symfony matches for the operation's route, the detachment and linkage facts from the Doctrine mapping, the include safeguards from the serializer the server renders the type with. Where a fact depends on code the bundle can't read, such as a persister of the application's own or a route it can't find, it answers that the code can arise, because a missing code hurts a client more than a spare one.

## Considered options

- Answering `AUTHENTICATION_REQUIRED` from each operation's declared security requirement: a stateless authenticator rejects a bad token on a public route too, so only the firewall knows.
- Leaving a duplicate client id to the database: Doctrine answered a `500` where the Laravel package answers `409 CLIENT_GENERATED_ID_ALREADY_EXISTS`, so both reference persisters now look the id up before they write, and the Doctrine persister also answers `409` when its own insert breaks the primary key because a concurrent create took the id after the lookup.

## Consequences

Only a firewall or `access_control` matcher that reads the path, method or host is judged against a request built from the route. One that reads the client's IP, the port or anything a matcher of the application's own may look at counts as matching, so its firewall's authenticators and its rule's roles document `401` and `403`, since a real request may match where the built one doesn't. Reading the matchers needs the firewall map's and access map's private members, and a map the bundle can't read counts as refusing every operation.
