# Validation is a Symfony Validator bridge over core's constraint vocabulary

Core declares constraints but never executes them, so the bundle translates each `ConstraintInterface` into Symfony constraints and validates the request document's attributes before hydration, so each violation maps to a `/data/attributes/<name>` pointer in a `422`. Presence and nullability resolve against the create/update context into `Required`/`Optional`, and rules that need a sibling value or a closure (`CompareField`, `When`, the date bounds) run as `Callback`s or a document-level pass, because Symfony's stock constraints cannot express them.

## Consequences

`symfony/validator` is optional, and without it writes run unvalidated. A constraint outside core's vocabulary needs a tagged `ConstraintTranslatorInterface`, or the bridge fails loudly.
