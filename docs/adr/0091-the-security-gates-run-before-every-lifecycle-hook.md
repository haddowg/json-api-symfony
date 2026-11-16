# The security gates run before every lifecycle hook

The security subscriber listens at priority 256 on each event it gates, and each gate sits on the first event of its step, so a caller it refuses never reaches a resource hook method or an application listener at the default priority. Both subscribers used to listen at 0, so the order depended on which was registered first, and the resource hooks ran first: a refused create ran its `beforeCreate` hook, with that hook's reads, side effects and errors.

## Considered options

- Leaving the create and update gates on `BeforeCreate` and `BeforeUpdate` behind a higher priority. `BeforeSave` fires before both, so its hook would still run for a refused caller. The gates moved to `BeforeSave` instead, which keeps the order of the hook events as it was.
- Calling the gates from the handler rather than from a subscriber. The gates stay events, so an application can still replace or extend them, and its listeners choose a side by priority.

## Consequences

- A body relationship's `BeforeRelationshipCommit` now fires before the update's `BeforeSave`, and on a relationship endpoint the member-move gate fires before `BeforeRelationshipMutate`, so neither check follows a hook. The linkage checks on that endpoint run before the parent's gate too, so a missing related id answers 404 ahead of the gate's 403.
- The changed-entity update gate no longer sees what a `beforeSave` or `beforeUpdate` hook writes. It judges the caller's change, and a hook's own write is the application's.
- The relationship endpoint's second gate still follows `BeforeRelationshipMutate`, because it judges the applied change. A refusal there rolls back what the hook wrote to the database, and nothing else.
