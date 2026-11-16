# A Doctrine morph filter compares declared columns and refuses a closure at warm-up

A `WhereMorph` runs in the query on the Doctrine provider, comparing the columns a `MorphTo` names with core's `identifiedByColumns()` on the main query and the fields a `MorphToMany` names with `identifiedByRows()` as one correlated `EXISTS` over its link rows. A relation identified only by an `identifiedBy()` closure has nothing the query can compare, so the non-optional `DoctrineServableWarmer` fails `cache:warmup` naming `identifiedByColumns()` rather than letting the first request 500 or quietly filtering in memory. The filter value validator checks each id against the format of the type the request names, since a morph filter's id constraints depend on the request.

## Considered Options

- Loading the page and filtering it in memory through the closure: pages come out short and totals wrong, as ADR 0053 found for identified to-many members.
- Inferring the columns from Doctrine metadata: Doctrine has no polymorphic association, so any guess at a `<name>Type`/`<name>Id` pair would be a convention nobody declared.
