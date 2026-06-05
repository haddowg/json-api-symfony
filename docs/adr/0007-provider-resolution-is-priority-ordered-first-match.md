# Provider resolution is priority-ordered first-match; the Doctrine provider is the lowest-priority fallback

A resource type can be claimed by more than one `DataProvider` — the reference
Doctrine provider supports *every* entity-mapped type, so "use my provider for
this one type" must not require touching the others. Rather than a per-type
override map in bundle configuration, resolution reuses the standard Symfony
tagged-iterator contract: providers are consulted in descending tag `priority`
order (default `0`), first `supports()` match wins, and the bundled Doctrine
provider registers itself at `-128`. An application provider therefore shadows
the fallback for the types it supports with zero configuration — relying on
definition order instead would make the override dependent on container
compilation internals, and a config map would duplicate what `supports()`
already expresses.
