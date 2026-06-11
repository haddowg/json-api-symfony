# The Doctrine related-collection scope branches on association arity (FK fast-path vs IN subquery)

`GET /{type}/{id}/{relationship}` for a to-many on the Doctrine provider no
longer requires a single-valued inverse association. The
`fetchRelatedCollection()` scope now branches: a single-valued inverse
association (the OneToMany case, whose related entity carries the owning foreign
key) keeps the FK fast-path
(`andWhere(resource.<owningField> = :parent)`); any other to-many — an
owning-side association, or a many-to-many on either side — scopes membership
via an `IN` subquery
(`resource.<id> IN (SELECT related.<id> FROM <Parent> parent JOIN parent.<rel> related WHERE parent = :parent)`).
The `LogicException` it previously threw for the owning-side / many-to-many case
is gone.

The subquery form keeps the **related** entity as the outer query root, so the
shared `DoctrineFilterHandler`/`DoctrineSortHandler` (which read
`getRootAliases()[0]`), the `count()` helper, and the `OffsetWindow`
`setFirstResult`/`setMaxResults` machinery all apply unchanged across both
branches — the only difference between an inverse-OneToMany and a many-to-many
related collection is how the parent scope is expressed. The in-memory provider
is unchanged: it reads the related objects off the parent and applies the shared
`CriteriaApplier`, for which a many-to-many collection is just another
`list<object>` property.

The branch predicate guards the FK fast-path with
`getClassMetadata($relatedClass)->isSingleValuedAssociation($owningField)`, not a
bare `$owningField !== null`: a many-to-many **inverse** side also has a non-null
`mappedBy`, but it points to a collection, so it must take the subquery path —
the bug a bare null-check would leave.

Builds on ADR 0030 (the queryable, paginated related-collection seam this branch
extends to many-to-many).
