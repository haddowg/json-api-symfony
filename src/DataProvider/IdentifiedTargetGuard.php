<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataProvider;

use haddowg\JsonApi\Resource\Field\MorphTo;
use haddowg\JsonApi\Resource\Field\MorphToMany;
use haddowg\JsonApiBundle\Security\ResourceSecurityRegistry;

/**
 * Decides which types of a `MorphTo` declared with `identifiedBy()` are guarded, so a
 * stored pair naming one can't render until its target is loaded and checked. A type is guarded
 * on a server when the relation declares its own read
 * permission, when the type declares a read gate there, or when its provider may hide
 * a row. The type-level answers are cached per server and type.
 *
 * A `MorphToMany` declared with `identifiedBy()` guards a type only when its provider may
 * hide a row, since a to-many's rows are never gated one by one ({@see \haddowg\JsonApiBundle\Security\ReadableRows}).
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
     * The types `$relation` declares whose pairs a scope may hide, so they are checked
     * against their provider before the linkage renders, a page is cut or a count taken.
     *
     * @return list<string>
     */
    public function guardedMemberTypes(MorphToMany $relation): array
    {
        $guarded = [];
        foreach ($relation->relatedTypes() as $type) {
            if ($this->providers->supportsType($type) && $this->scopes($relation, $type)) {
                $guarded[] = $type;
            }
        }

        return $guarded;
    }

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

        return $this->scopes($relation, $type) || $this->readGated($type, $server);
    }

    /**
     * Whether the provider of `$type` may leave out a row of it that exists.
     */
    private function scopes(MorphTo|MorphToMany $relation, string $type): bool
    {
        $provider = $this->providers->forType($type);
        if (!$provider instanceof FetchesByIdsInterface) {
            // The answer may depend on the relation, so it isn't cached.
            return !$provider instanceof ScopesRelatedReadsInterface || $provider->scopesRelatedReads($relation);
        }

        return $this->types[$type] ??= $provider->scopesFetchByIds($type);
    }

    private function readGated(string $type, string $server): bool
    {
        return \is_string($this->security?->securityFor($type, $server)?->forRead());
    }
}
