# A segregated transaction seam binds post-commit hooks to the batch commit

Atomic Operations needs every write in a batch to commit or roll back together, but the
bundle had no transaction boundary — each write flushed autonomously. Rather than break
the frozen `DataPersisterInterface`, transactionality is a **segregated**
`TransactionalDataPersisterInterface` (`beginTransaction`/`commit`/`rollback`): the
Doctrine implementation wraps the connection (commit flushes-then-commits, rollback rolls
back and clears the unit of work, leaving the manager open and reusable) and the in-memory
implementation snapshots/restores its store. The existing per-operation `flush()` calls are left
untouched, because a Doctrine `flush()` inside an already-open transaction is non-durable
until commit yet still materialises auto-increment ids immediately — empirically verified
against the Doctrine+sqlite kernel — which is exactly what cross-operation `lid`
resolution needs.

`After*` lifecycle hooks are re-bound from "after the per-operation flush" to "after the
transaction commits": a request-scoped `WriteTransactionContext` buffers their dispatch
and drains once, after the batch commits (discarding them on rollback). The
single-operation path never activates the context, so its hooks fire inline exactly as
before and the refactor is behaviour-preserving. The honest boundary, documented for
authors: only side effects routed through `After*` are made atomic — a non-transactional
side effect performed inside a `Before*` hook, or any external call, cannot be rolled
back, the same contract any transactional ORM gives.
