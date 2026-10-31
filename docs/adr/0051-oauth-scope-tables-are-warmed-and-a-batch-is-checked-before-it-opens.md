# OAuth scope tables are warmed into the cache, rule dependence is read from the expression, and a batch is checked before it opens

Each server's scope table is built by a non-optional cache warmer and written as a PHP file, so a throwing resolver, an override naming a missing operation or a scoped public operation fails `cache:warmup` instead of a request, and a request reads a file opcache already holds. Whether a rule depends on the resource is decided by parsing the Symfony expression for `object` or `subject`, rather than asking the author to declare it. The Atomic Operations backend checks every entry against the request's grant before it opens a transaction, so a refused batch never runs writes or hooks.

## Consequences

When the table file is missing it is built on first use through a lazy metadata proxy. Evaluating a rule for a user other than the caller swaps a token for that user into the token storage before Symfony 7.3, because the access decision manager alone answers a nested `is_granted()` for the current caller.
