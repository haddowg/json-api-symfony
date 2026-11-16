# The security gates run before every lifecycle hook

The security subscriber listens at priority 256 on each event it gates, and each gate sits on the first event of its step, so a caller it refuses never reaches a resource hook method or an application listener at the default priority. Both subscribers used to listen at 0, so the order depended on which was registered first, and the resource hooks ran first: a refused create ran its `beforeCreate` hook, with that hook's reads, side effects and errors.

## Considered options

- Leaving the create and update gates on `BeforeCreate` and `BeforeUpdate` behind a higher priority. `BeforeSave` fires before both, so its hook would still run for a refused caller. The gates moved to `BeforeSave` instead, which keeps the order of the hook events as it was.
- Calling the gates from the handler rather than from a subscriber. The gates stay events, so an application can still replace or extend them, and its listeners choose a side by priority.

## Consequences

- A body relationship's `BeforeRelationshipCommit` now fires before the update's `BeforeSave`, so it doesn't follow a hook.
- A relationship endpoint gates the parent at a new event-only `BeforeApplyRelationship`, before the related ids are looked up, and then runs the linkage, membership and member-move checks ahead of `BeforeRelationshipMutate`. The gate and the hooks shared `BeforeRelationshipMutate`, so no priority could put the related-id lookup between them. A refused caller gets the 403 before any lookup and learns nothing about which related ids exist, as on Laravel, which checks the parent first too. A listener that used to throw from `BeforeRelationshipMutate` above the gate's priority to run first now needs `BeforeApplyRelationship`.
- The changed-entity update gate no longer sees what a `beforeSave` or `beforeUpdate` hook writes. It judges the caller's change, and a hook's own write is the application's.
- The relationship endpoint's second gate still follows `BeforeRelationshipMutate`, because it judges the applied change. A refusal there rolls back what the hook wrote to the database, and nothing else.
