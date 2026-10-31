# A morph relation is polymorphic by declaration, but loads by its type count

One rule decides whether a relation is polymorphic, for every request decision and for the OpenAPI metadata: it is a core `PolymorphicRelationInterface` or lists several types. So a `MorphTo`/`MorphToMany` over a single type still offers no filter, sort or page vocabulary, matching the document core projects. How its members load still goes by the count: a single-type morph is one query, and Doctrine refuses only a to-many whose members span entity classes, which needs a custom provider.
