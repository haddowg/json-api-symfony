# Scope tables are warmed into the cache, the evaluator reads the expression, and a batch is checked before it opens

Core enforces OAuth scopes from a table it can build at any time (core ADR 0181), so the bundle builds each server's table in a non-optional cache warmer and writes it as a PHP file in the cache directory. A resolver that throws, an override naming an operation the type lacks, or a public operation given a scope then fails `cache:warmup` and the container build instead of a request, and a request reads a file opcache already holds rather than walking the metadata. When the file is missing the table is built from the server's metadata on first use, through a lazy metadata proxy, because a server's enforcer is created with the server and the metadata is built from that server.

The catalogue's evaluator (core ADR 0183) decides whether a rule needs the resource by parsing the Symfony expression and looking for `object` or `subject`, rather than asking the author to declare a level. An expression that reads either is `Depends`, and any other is judged by Symfony Security there and then: for a given user through `isGrantedForUser()`, or before Symfony 7.3 with a token for that user swapped into the token storage, because the access decision manager alone would answer a nested `is_granted()` for the current caller instead.

The Atomic Operations backend checks every entry of a batch against the batch request's grant in its constructor, before `begin()` opens a transaction, using core's `OperationKey::forAtomicOperation()` for the operation each entry performs. Checking inside the loop would refuse a batch only after earlier entries had run their writes and hooks inside the transaction; checking first means a refused batch never opens one.

## Consequences

The app's scope services are found by the core interface each implements: the bundle autoconfigures the four interfaces and aliases each to its single implementation, since Symfony only aliases an interface automatically when it sits in the namespace being loaded.
