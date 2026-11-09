# The bundle reports declared identifier meta straight from the core relation

`RelationMetadata` implements core's `DeclaresIdentifierMeta` by returning the relation's own `identifierMetaFields()`, with no bundle configuration or attribute of its own, because the resolver lives on the relation and the members describing it belong beside it. The bundle adds nothing on either data layer: the resolver already reads what the provider loaded for the linkage, and the members are read-only, so writes and validation ignore them.
