# Entity-level validation runs as a post-hydration pass over a marker interface

Most constraints validate the request document before hydration, but some need the
**persisted object or the database** — uniqueness above all — which raw attributes
can't answer (and which, on update, must exclude the current record by its
identifier). Rather than special-case `UniqueEntity`, the bridge adds a reusable
seam: a constraint that implements `EntityConstraintInterface` is **skipped** in
the document-first pass and validated by `ResourceValidator::validateEntity()`
against the hydrated entity instead. `CrudOperationHandler` calls that pass after
the hydrator builds the entity and before the persister commits, so a duplicate
surfaces as a `422` (pointing at the offending field) before anything is written.

The seam is general, not Doctrine-specific: an entity-level constraint translates
through the same `ConstraintTranslator` (and the `ConstraintTranslatorInterface`
extension point) to a Symfony **class** constraint validated against the object, so
an application hooks its own rule in by implementing the marker and registering a
translator. `UniqueEntity` (bundle, Doctrine) is the first consumer — it carries
only the field(s) and translates to doctrine-bridge's `UniqueEntity`, leaving the
entity class to be inferred from the object and create-vs-update exclusion to
Symfony's validator. The pass is a no-op when no entity-level constraint is
declared, so the in-memory provider is unaffected.
