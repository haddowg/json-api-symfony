# Callbacks registered during a write run once its transaction commits

`AfterCommit` holds the callbacks an application registers during a write and runs them once that write's transaction commits, ahead of the after hooks and the response, and drops them when it rolls back. An action's `AfterActionEvent` fires inside its transaction and a `202` skips the after hooks, so before this an action or a persister had no way to queue work, such as a message a worker loads the new row for, only once that row was committed. Callbacks follow the commit rather than the response, so an action that returns an error response still commits and still runs them.

## Considered options

- Firing `AfterActionEvent` after the commit, as the CRUD after hooks are. That would stop a subscriber's throw from undoing the action, which 2.17 made the rule, and it still leaves the `202` path with nothing.
- Keeping the callbacks on `WriteTransactionContext`. That context is the batch flag and the queue of a batch's deferred after hooks, which only exist while a batch runs. Callbacks need a scope for every write, with or without a transaction, so they get their own service, and the batch backend opens one scope around its operations.

## Consequences

- `WriteTransaction::run()` opens a scope for every write, even one that runs without a transaction, such as a `GET` action or one on a persister that can't transact. Those run their callbacks when the write returns and drop them when it throws.
- Scopes nest. An inner write hands its callbacks to the outer one, and only the outermost commit runs them, so a batch's operations wait for the batch.
- A callback that throws is logged at error level and the rest still run, because the write is already committed. A dispatch that fails there loses the message, so work that must not be lost still belongs on a transport that commits with the write.
- Registering outside any write runs the callback at once.
- Only the bundle's own transactions count. A bundle write inside a transaction the application opened itself runs its callbacks when the bundle's write returns, because Doctrine has no hook for the outer commit. The Laravel adapter can wait for that commit through `DB::afterCommit`, so this is one place the two differ.
