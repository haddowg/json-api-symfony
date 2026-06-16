# ?withCount honours the relation's active filters

`?withCount=<rel>` emits each parent's to-many `<rel>` cardinality as the
relationship object's `meta.total`. Until now both providers counted **raw**
membership and ignored any active relation filter (Doctrine: a parent-rooted
`LEFT JOIN … GROUP BY` over the whole association; in-memory: `count()` of the
related value), so a relation that ALSO carried a `relatedQuery[<rel>][filter]`
reported a count that disagreed with the same relation's related-collection
endpoint total — the endpoint applies that filter, the count did not. We made the
count honour the relation's filters so the two describe the **same** filtered set:
this is a deliberate behaviour change (raw membership → filtered count). The common
case is unchanged — a `?withCount` relation with no relatedQuery filter has an empty
criteria, so the count is raw membership exactly as before.

`DataProviderInterface::countRelated()` gains a filters-only `CollectionCriteria`
(no window — a count needs no page; no sort — order is irrelevant to a count). The
`RelationCountBatcher` builds it per relation through the shared
`RelationCriteriaFactory` (the same merge the related endpoint and the include-window
batcher use) from the request's `relatedQuery[<rel>][filter]`, so the count resolves
the **identical** vocabulary and the same unknown-key `400`. The factory always sets
the related resource's `defaultSort()` on the criteria (it serves the ordered related
endpoint too), so the batcher explicitly **clears it** for the count: a count needs no
order, and the Doctrine count roots on the **parent** while a related resource's
default-sort column lives on the joined `related` entity — an un-cleared default order
would emit `ORDER BY parent.<relatedColumn>` on the grouped aggregate, a column the
parent does not have, which the SQL engine rejects (a hard error). Dropped, the count
stays crash-free and byte-identical to the in-memory witness regardless of whether the
related resource declares a default order. The in-memory provider —
the witness that defines correctness — applies the criteria over each parent's
related value via the shared `CriteriaApplier` and counts the survivors (an empty
filtered set is `0`). The Doctrine provider keeps its one parent-rooted grouped
`COUNT` and applies the filters on the **`related` join alias** (the count passes
`related` as a new `$defaultAlias` to the alias-aware applier — every related filter
key lands on the join, not the `parent` root); because an `andWhere` on a `LEFT JOIN`
drops a zero-match parent from the grouped result, the page is **zero-filled** (every
parent seeded to `0`, then the query rows overlaid) so a filtered-out parent reports
`0`, matching the witness. The budget is still ONE query per relation per page; the
zero-fill is in-PHP. The unfiltered count adds no predicate, so its query — and the
QueryCountingDoctrineKernel budget probe — is unchanged.

Two boundaries fall out of the parent-rooted shape. A relationship-existence filter
(`WhereHas`/`WhereDoesntHave`) re-roots on the count query's own root (the parent),
so routed to `related` it would scope the parent, not the related members — it is
rejected on the count path (the related endpoint, which roots on the related entity,
still supports it; supply a custom provider to count it). A pivot relation joins the
far member as `related` and filters there ONLY when the criteria carries a filter, so
the common unfiltered pivot count keeps its original distinct-member query (the budget
is preserved); pivot-FIELD count filtering is out of scope for this slice (the count
criteria is built without the pivot-field layer). A polymorphic to-many keeps the
existing support matrix — in-memory counts the mixed filtered set, Doctrine throws.

**BEHAVIOUR CHANGE.** A `?withCount`-named relation that also carries a relatedQuery
filter now counts its filtered set rather than raw membership, so its `meta.total`
can shrink relative to prior releases (it now equals the related endpoint's filtered
total). This INCLUDES relation/related-resource filter **defaults**: a related
resource that declares a filter with a default (e.g. `explicit=false`) now folds that
default into the count, exactly as the related-collection endpoint already does — so
`?withCount` of such a relation drops to the default-scoped total even with no
explicit relatedQuery filter. A relation with no filter (and no filter defaults) is
unaffected.
