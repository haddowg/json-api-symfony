# The Doctrine layer supports ORM 2.20 by reading associations through ClassMetadata

ORM 3 returns association mappings as objects (`$mapping->targetEntity`,
`$mapping->isOwningSide()`) where ORM 2 returns arrays, and ORM 3 deprecates array access
on those objects ahead of ORM 4. So neither shape works across `^2.20 || ^3.0`. The layer
reads associations only through the `ClassMetadata` methods both majors share
(`getAssociationNames()`, `getAssociationTargetClass()`, `isAssociationInverseSide()`,
`getAssociationMappedByTargetField()`). That is simpler than a version-sniffing accessor
and leaves no version check in the code. A dedicated CI job (ORM 2.20, DBAL 3,
`doctrine/persistence` 2.5, Symfony 6.4) catches a regression to the object API, and a
`conflict` on `doctrine/orm <2.20 || >=4.0` makes an unsupported ORM fail at install time
rather than on the first relationship query, since ORM is only a `suggest` dependency.
