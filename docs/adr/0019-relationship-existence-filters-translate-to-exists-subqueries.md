# Relationship-existence filters translate to EXISTS subqueries on Doctrine

Core's `WhereHas` / `WhereDoesntHave` filters match rows whose named association
has (or lacks) at least one related row, ignoring the request value entirely;
the bundle's `DoctrineFilterHandler` had to choose how to push that down to DQL.
A `JOIN` onto the association is the obvious path, but it leaks join cardinality
into the primary `SELECT`: a to-many fans the article rows out per related row,
so the result needs a `DISTINCT` to undo the fan-out (and `DISTINCT` then
interacts badly with the pagination `COUNT` and the sort `ORDER BY`). We instead
translate each to a correlated `EXISTS` (negated for `WhereDoesntHave`) subquery
that re-roots on the same entity, joins the association, and correlates its root
back to the outer root — pure set-membership, so the primary-data rows are
neither duplicated nor in need of a `DISTINCT`, the existing pagination/sort
pipeline is untouched, and **a to-one and a to-many translate identically** (the
join is over the association, not its arity). This mirrors core's in-memory
witness, which matches a non-empty collection/array or a non-null to-one, so the
same dual-provider conformance assertions pass on both providers.

The subquery is built from the QueryBuilder's own root entity and entity manager
(no target-entity lookup needed), and the association name is validated as a
bare identifier before interpolation — the same loud-failure discipline the
scalar-column `path()` already applies, since the relationship name comes from
the server-side declaration, never the client.
