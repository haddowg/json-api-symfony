<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider;

use haddowg\JsonApi\Resource\Field\MorphTo;
use haddowg\JsonApiBundle\Security\ResourceSecurityRegistry;

/**
 * Decides which types of a `MorphTo` declared with `identifiedBy()` are guarded, so a
 * stored pair naming one can't render until its target is loaded and checked (bundle
 * ADR 0163). A type is guarded on a server when the relation declares its own read
 * permission, when the type declares a read gate there, or when its provider may hide
 * a row. The type-level answers are cached per server and type.
 *
 * @internal
 */
final class IdentifiedTargetGuard
{
    /**
     * @var array<string, bool>
     */
    private array $types = [];

    public function __construct(
        private readonly DataProviderRegistry $providers,
        private readonly ?ResourceSecurityRegistry $security = null,
    ) {}

    /**
     * The types `$relation` declares whose pairs need their target loaded before the
     * linkage renders on `$server`. A type no provider serves is never among them,
     * since nothing could load it.
     *
     * @return list<string>
     */
    public function guardedTypes(MorphTo $relation, string $server): array
    {
        $guarded = [];
        foreach ($relation->relatedTypes() as $type) {
            if ($this->guards($relation, $type, $server)) {
                $guarded[] = $type;
            }
        }

        return $guarded;
    }

    private function guards(MorphTo $relation, string $type, string $server): bool
    {
        if (!$this->providers->supportsType($type)) {
            return false;
        }

        if (\is_string($relation->securityRead())) {
            return true;
        }

        $provider = $this->providers->forType($type);
        if (!$provider instanceof FetchesByIdsInterface) {
            // The answer may depend on the relation, so it isn't cached.
            if (!$provider instanceof ScopesRelatedReadsInterface || $provider->scopesRelatedReads($relation)) {
                return true;
            }

            return $this->readGated($type, $server);
        }

        return $this->types[$server . "\0" . $type] ??= $provider->scopesFetchByIds($type) || $this->readGated($type, $server);
    }

    private function readGated(string $type, string $server): bool
    {
        return \is_string($this->security?->securityFor($type, $server)?->forRead());
    }
}
