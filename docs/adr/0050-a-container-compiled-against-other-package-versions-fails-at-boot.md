# A container compiled against other package versions fails at boot

Symfony checks a non-debug container for freshness only when it first builds it, so after an upgrade a cached test or production container keeps running the old wiring and an application's tests can pass against behaviour the installed code no longer has. The bundle records the installed versions of itself and of `haddowg/json-api` as a container parameter at compile time, and `boot()` throws, telling the user to clear that environment's cache, when they differ from the installed ones.

## Consequences

The source reference is compared only for release versions, because a `dev-` version moves its reference while the container stays valid. A container that recorded nothing counts as stale; nothing is checked without `Composer\InstalledVersions`, or while `cache:clear` boots the stale container to replace it.
