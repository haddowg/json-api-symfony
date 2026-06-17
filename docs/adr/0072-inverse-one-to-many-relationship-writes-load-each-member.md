# Inverse one-to-many relationship writes load each member (accepted N+1)

A relationship write whose foreign key lives on the **child** entity — an inverse
one-to-many such as `articles.comments` (the FK is `comment.article_id`) — re-points
each incoming member by setting its owning-side association
(`DoctrineDataPersister::attachOwner()` → `$member->{$owningField} = $owner`). Setting
a field on an `EntityManager::getReference()` proxy **initialises it**, so a create /
replace / add / remove of such a relation issues **one `SELECT` per incoming linkage
id** — the SELECT budget is O(linkage size). A **many-to-many** the parent owns (the
join-table side) does not have this cost: the member is added to the owning collection
from its known id and the join row inserts without loading it (O(1) regardless of
linkage size). The pre-v1 query-budget audit (2026-06-17) found and pinned both
behaviours in `DoctrineWriteQueryBudgetTest`.

We **accept** the inverse one-to-many cost for v1 rather than fix it, because
re-pointing N managed children through the ORM inherently requires them managed (the
unit of work tracks each FK change and the inverse-collection sync), so the loads are
the idiomatic cost; the only O(1) alternative — a bulk `UPDATE … WHERE id IN (...)` —
**bypasses the unit of work and the children's lifecycle/cascade events**, a
correctness trade-off not worth making for a cost that only bites *large* to-many
re-points (a handful of ids is negligible). It is documented on
[doctrine.md](../doctrine.md) and witnessed by a skipped target test that becomes the
regression assertion if the path is ever optimised (e.g. behind an opt-in bulk-write
flag). An author who needs O(1) bulk re-pointing today can supply a custom
`DataPersister` that issues the bulk update.
