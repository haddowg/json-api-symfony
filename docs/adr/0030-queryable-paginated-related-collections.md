# Related collections are queryable, paginated collections served by the related type's provider

A to-many's related endpoint and relationship endpoint are collections at parity with a primary collection: filter, sort and page apply against the related type's vocabulary merged with the relation's own `withFilters()`/`withSorts()` (the relation wins a key clash, and its keys exist only on its own endpoints), and pagination resolves relation, then related resource, then server default. They execute through `DataProviderInterface::fetchRelatedCollection()` scoped to the parent, so Doctrine pushes the scope, filters and window into SQL instead of loading the association.

## Consequences

- Doctrine scopes by the inverse foreign key when the related entity holds it, and by an `IN` subquery otherwise (owning side, many-to-many).
- The relationship endpoint's page reaches core out of band (ADR 0053), never written onto the parent.
