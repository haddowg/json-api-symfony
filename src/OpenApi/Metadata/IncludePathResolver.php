<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\OpenApi\Metadata;

use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Server\Server;
use haddowg\JsonApiBundle\Server\TypeMetadataResolver;

/**
 * Derives the dotted `?include` paths a type advertises (design §4.4 / the contract's
 * {@see \haddowg\JsonApi\OpenApi\Metadata\TypeMetadataInterface::includablePaths()}),
 * by walking the relation graph from a root type — neither core nor the bundle
 * stores the resolved path set, so the metadata source derives it here.
 *
 * The walk honours every include safeguard so the advertised paths match what core
 * would actually accept:
 *  - **per-relation includability**: a relation with `cannotBeIncluded()` (i.e.
 *    `isIncludable()` false) contributes no path and is not descended through;
 *  - **max include depth**: the effective cap is the root resource's own
 *    `maxIncludeDepth()` when set, else the server default
 *    ({@see Server::maxIncludeDepth()}); a `null` cap is unlimited (but cycle
 *    detection still terminates the walk);
 *  - **allow-list**: when the root resource declares a `getAllowedIncludePaths()`
 *    whitelist, only paths it permits (a path is permitted when it is a prefix of,
 *    or equal to, an allowed path) are advertised.
 *
 * Cycle safety: a path that revisits a type already on the current branch is not
 * descended again (so a mutual `author`/`articles` cycle yields the finite prefix
 * set, not an infinite walk), independent of the depth cap.
 *
 * (Post-1.0 opportunity: core could expose a shared includable-path enumerator that
 * both this and core's `?include` validation consult. Kept bundle-side for 1.0 so no
 * reactive core surface is frozen at the tag — this walk is correct and self-contained.)
 *
 * @internal
 */
final class IncludePathResolver
{
    public function __construct(private readonly TypeMetadataResolver $types) {}

    /**
     * The includable dotted paths for `$type` on `$server`, as the primary type of
     * its own collection / read endpoints. Empty when the type has no includable
     * relations (or no resource).
     *
     * @return list<string>
     */
    public function pathsFor(Server $server, string $type): array
    {
        $resource = $this->types->resourceFor($server, $type);
        $maxDepth = $resource?->maxIncludeDepth() ?? $server->maxIncludeDepth();
        $allowList = $resource?->getAllowedIncludePaths();

        return $this->walk($server, $type, '', [$type], $maxDepth, $allowList);
    }

    /**
     * The includable dotted paths valid on a **related** endpoint
     * (`GET /{type}/{id}/{rel}`), scoped to the *related* type (not the parent): the
     * related resource is the primary data there, so the walk roots on it with its
     * own depth cap / allow-list. For a polymorphic relation (several related types
     * sharing no include vocabulary) the result is empty.
     *
     * @return list<string>
     */
    public function relatedPathsFor(Server $server, RelationInterface $relation): array
    {
        $relatedTypes = $relation->relatedTypes();
        if (\count($relatedTypes) !== 1) {
            // Monomorphic only: a polymorphic related endpoint has no single related
            // type to root the include vocabulary on.
            return [];
        }

        return $this->pathsFor($server, $relatedTypes[0]);
    }

    /**
     * Depth-first walk of `$type`'s includable relations, accumulating dotted paths
     * under `$prefix`. `$branch` is the list of types already on the current branch
     * (cycle guard); `$maxDepth` is the effective cap; `$allowList` (when non-null)
     * filters paths to the root's whitelist.
     *
     * @param list<string>      $branch
     * @param list<string>|null $allowList
     *
     * @return list<string>
     */
    private function walk(Server $server, string $type, string $prefix, array $branch, ?int $maxDepth, ?array $allowList): array
    {
        $depth = $prefix === '' ? 0 : \substr_count($prefix, '.') + 1;
        if ($maxDepth !== null && $depth >= $maxDepth) {
            return [];
        }

        $paths = [];
        foreach ($this->types->relationsFor($server, $type) as $relation) {
            if (!$relation->isIncludable()) {
                continue;
            }

            // The related type(s) must be serializable on THIS server. In a multi-server
            // setup a relation may point at a type registered only on another server
            // (e.g. `owner` → `users`, where `users` lives on the admin server): the
            // relation renders links-only here and an `?include` of it hydrates nothing,
            // so advertising the path would emit an include token — and a dead codegen
            // accessor — the server can never fulfil (D45).
            if (!$this->relatedTypesSerializable($server, $relation)) {
                continue;
            }

            $path = $prefix === '' ? $relation->name() : $prefix . '.' . $relation->name();
            if (!$this->allowed($path, $allowList)) {
                continue;
            }

            $paths[] = $path;

            // Descend only into a monomorphic related type not already on this branch
            // (cycle guard). A polymorphic relation has no single type to descend.
            $relatedTypes = $relation->relatedTypes();
            if (\count($relatedTypes) !== 1) {
                continue;
            }

            $relatedType = $relatedTypes[0];
            if (\in_array($relatedType, $branch, true)) {
                continue;
            }

            foreach ($this->walk($server, $relatedType, $path, [...$branch, $relatedType], $maxDepth, $allowList) as $nested) {
                $paths[] = $nested;
            }
        }

        return $paths;
    }

    /**
     * Whether every type the relation can resolve to is serializable (renderable) on
     * `$server` — the gate that keeps the projection from advertising an include the
     * server cannot hydrate. A monomorphic relation checks its single related type; a
     * polymorphic one requires **all** member types (an include reaching an
     * unrenderable member is a partial contract, so the whole path is pruned).
     */
    private function relatedTypesSerializable(Server $server, RelationInterface $relation): bool
    {
        $relatedTypes = $relation->relatedTypes();
        if ($relatedTypes === []) {
            return false;
        }

        foreach ($relatedTypes as $relatedType) {
            if (!$server->hasSerializerFor($relatedType)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether `$path` is permitted by the root resource's allow-list: always true
     * when there is no list; otherwise true when `$path` equals, or is a dotted
     * prefix of, any allowed path (so an intermediate hop on the way to a deeper
     * allowed path is itself advertised).
     *
     * @param list<string>|null $allowList
     */
    private function allowed(string $path, ?array $allowList): bool
    {
        if ($allowList === null) {
            return true;
        }

        foreach ($allowList as $allowed) {
            if ($allowed === $path || \str_starts_with($allowed, $path . '.')) {
                return true;
            }
        }

        return false;
    }
}
