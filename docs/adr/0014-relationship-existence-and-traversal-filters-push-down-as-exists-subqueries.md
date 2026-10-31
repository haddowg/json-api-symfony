# Relationship-existence and traversal filters push down as EXISTS subqueries

Doctrine executes `WhereHas`/`WhereDoesntHave`, dotted `WhereThrough` paths and the bundle's `WhereHasMatching` through one builder that emits a correlated `EXISTS` subquery rooted on the related entity, rather than a join on the primary query. A join fans out to-many rows and needs a `DISTINCT` that interacts badly with the pagination count and the sort; set membership leaves the primary rows, counts and sort untouched and treats to-one and to-many identically.

## Consequences

`WhereHasMatching` is a Doctrine-only escape hatch (a `Criteria` or a closure over the subquery) for predicates the portable vocabulary cannot express. It is not value-validated, and other providers do not declare it, so they answer `400`.
