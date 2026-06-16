# A relation may add scoped filters and sorts to its related collection

A relation can declare extra `filters()`/`sorts()` (core ADR 0051, the relation
builders `withFilters()`/`withSorts()`) that augment **only** its related to-many
endpoint `GET /{type}/{id}/{rel}` — never the primary `/{relatedType}` collection.
The natural home for a contextual filter/sort: ordering a playlist's tracks, or a
filter only meaningful when listing a user's posts. Declaring it on the relation
*scopes* it there; declaring the same filter on the related resource would expose
it everywhere that type is listed.

Core only lets a relation **carry** the vocabulary; the bundle owns the merge and
the application. In `CrudOperationHandler::fetchRelated` (the to-many arm) the
handler builds the effective vocabulary as
`relatedResource->filters() + relation->filters()` and
`relatedResource->allSorts() + relation->sorts()`, keyed by `key()` so that on a
**clash the relation's declaration wins** (the more specific scope), then threads
the merged set onto the `CollectionCriteria`. Both providers apply whatever
filter/sort the criteria carry (the in-memory `CriteriaApplier`, the Doctrine
`fetchRelatedCollection` push-down `QueryBuilder`), so **neither provider needed a
change**. The merge happens *only* at `fetchRelated`, never in the primary
collection path — so the scoping is a pure host guarantee: a relation-scoped key on
`/{relatedType}` is an unrecognized filter/sort and 400s exactly as before, and a
key in neither set still 400s on the related endpoint too.

Relation-scoped filters/sorts operate on the **related entity** (the common case)
and work out of the box. **Pivot / join-table columns** (e.g. a many-to-many
position column) require the join to be modelled as an association entity and are
wired by the framework itself — see ADR 0045 (`belongsToMany` pivot data).
