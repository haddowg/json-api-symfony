<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Security;

use haddowg\JsonApi\OpenApi\Metadata\RelationMetadataInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApiBundle\Server\ServerProvider;

/**
 * The registry of declarative {@see ResourceSecurity} expression sets,
 * keyed by server and type. Built from plain scalar
 * `type → {default, create, update, delete, read, list}` maps the
 * {@see \haddowg\JsonApiBundle\DependencyInjection\Compiler\ResourceSecurityPass}
 * assembles from each resource's `#[AsJsonApiResource(security: …)]` tag attributes
 * (the maps flow through the container as scalars; value objects are not dumpable).
 *
 * Two servers may expose the same type name through different resource classes, so
 * each server holds only what its own resource declared: a server whose resource
 * declared nothing for a type is absent for it, and {@see securityFor()} returns
 * `null` there whatever another server declares. A resource on several servers is
 * registered under each.
 */
final class ResourceSecurityRegistry
{
    /** @var array<string, array<string, ResourceSecurity>> */
    private array $byServer = [];

    /**
     * @param array<string, array{default?: string|bool|null, create?: string|bool|null, update?: string|bool|null, delete?: string|bool|null, read?: string|bool|null, list?: string|bool|null}>                        $expressions the default server's type-keyed declarations
     * @param array<string, array<string, array{default?: string|bool|null, create?: string|bool|null, update?: string|bool|null, delete?: string|bool|null, read?: string|bool|null, list?: string|bool|null}>> $servers     every other server's, keyed by server name then type
     */
    public function __construct(array $expressions = [], array $servers = [])
    {
        $servers[ServerProvider::DEFAULT_SERVER] = ($servers[ServerProvider::DEFAULT_SERVER] ?? []) + $expressions;

        foreach ($servers as $server => $types) {
            foreach ($types as $type => $set) {
                $security = new ResourceSecurity(
                    default: $set['default'] ?? null,
                    create: $set['create'] ?? null,
                    update: $set['update'] ?? null,
                    delete: $set['delete'] ?? null,
                    read: $set['read'] ?? null,
                    list: $set['list'] ?? null,
                );

                if (!$security->isEmpty()) {
                    $this->byServer[$server][$type] = $security;
                }
            }
        }
    }

    /**
     * The expression set the resource for `$type` on `$server` declared, or `null` when
     * it declared none there (the subscriber then leaves the operation ungated by this
     * layer).
     */
    public function securityFor(string $type, string $server = ServerProvider::DEFAULT_SERVER): ?ResourceSecurity
    {
        return $this->byServer[$server][$type] ?? null;
    }

    /**
     * The collection read expressions of `$types` on `$server`, the check `GET /{type}`
     * makes, keyed by type. A type without one is left out.
     *
     * @param list<string> $types
     *
     * @return array<string, string>
     */
    public function collectionGates(array $types, string $server = ServerProvider::DEFAULT_SERVER): array
    {
        $gates = [];
        foreach ($types as $type) {
            $expression = $this->securityFor($type, $server)?->forList();
            if (\is_string($expression)) {
                $gates[$type] = $expression;
            }
        }

        return $gates;
    }

    /**
     * The collection read expressions a related or relationship read through `$relation`
     * must pass besides its own read gate, keyed by related type: every related type's,
     * for a to-many, since its endpoints show a list whole or not at all. A to-one has
     * none, and neither has a relation declaring its own read permission, which replaces
     * them.
     *
     * @return array<string, string>
     */
    public function relatedCollectionGates(RelationInterface|RelationMetadataInterface $relation, string $server = ServerProvider::DEFAULT_SERVER): array
    {
        if (!$relation->isToMany() || $relation->securityRead() !== null) {
            return [];
        }

        return $this->collectionGates($relation->relatedTypes(), $server);
    }
}
