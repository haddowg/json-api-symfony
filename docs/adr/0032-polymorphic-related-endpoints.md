# The related endpoints render polymorphic relationships per related object

`GET /{type}/{id}/{rel}` and `?include` now render polymorphic relationships. A
polymorphic to-one (a `MorphTo`) resolves its serializer from the **actual related
object** (`RelationInterface::resolveSerializer()`, core ADR 0036) rather than from
`relatedTypes()[0]`, so a `pinned` relation declared over `notes`/`images` renders
whichever type the related object is; an empty to-one still renders `data: null`
because `resolveSerializer(null, …)` falls back to the first registered serializer.
A polymorphic to-many (a `MorphToMany`, core ADR 0037) renders its mixed-type
members through a `Serializer\PolymorphicSerializer` that resolves each member's
serializer from the member object — so each member keeps its own correct
`type`/`id`/attributes with no transformer change.

A polymorphic to-many carries no single related type, so it has no shared
filter/sort vocabulary and no related-resource paginator: the handler passes empty
filter/sort vocabularies and resolves pagination as `relation paginator → server
default`. The in-memory provider needs no change — it reads the mixed collection
off the parent via `readValue` and applies the shared criteria + window — so a
requested `filter`/`sort` raises the usual 400 (unrecognised) while `page` slices,
the intended boundary.

The Doctrine provider throws "unsupported" for a polymorphic to-many: like its
many-to-many subquery boundary (ADR 0031), it executes one scoped query against a
single related entity class, and a polymorphic collection's members span entity
classes, so there is no single query to run. A host that needs it supplies a custom
`DataProvider` that resolves the related members across types. The boundary fires
before any EntityManager use, on `relatedTypes()` arity.
