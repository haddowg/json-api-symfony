# An identified MorphToMany narrows its pairs by id before it pages, counts or renders

A `MorphToMany` declared with `identifiedBy()` checks each guarded type once, with an id-only query over all of a parent's stored pairs, before it cuts a page or takes a count, and only then loads the rows the page shows, so a hidden member never makes a page short or a total wrong and a large membership never loads in full. A type is guarded only when its provider may hide a row, since a to-many's rows are not gated one by one, and the related endpoint keeps the polymorphic contract: pages in pair order, `filter` and `sort` a `400`.

## Considered options

- Loading every member's row to narrow them: correct, but a parent with thousands of members loads them all to show one page.
- Narrowing the loaded page only: pages come out short and totals count rows the caller can't see.
