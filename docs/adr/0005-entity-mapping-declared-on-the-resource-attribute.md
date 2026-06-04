# The Doctrine entity mapping is declared on the resource via `#[AsJsonApiResource(entity: …)]`

The Doctrine provider needs a `type → entity class` map, and the alternatives —
a central bundle-config map, or a naming convention with inflection — either
split the declaration away from the resource that owns it or break on irregular
names. The mapping is instead a parameter on the existing discovery attribute
(`#[AsJsonApiResource(entity: Article::class)]`): the resource declaration is
the one place that already knows what it represents, core stays
storage-agnostic (the attribute is a bundle concept), and the
`DoctrineEntityMapPass` compiles the map with build-time validation (missing
entity class, undeterminable type, or one type mapped to two entities all fail
the container build).

With an empty map the pass removes the `DoctrineDataProvider` definition
entirely: a provider that can answer for no type must not keep a reference to
the (possibly absent) `EntityManagerInterface` service alive in non-Doctrine
applications.
