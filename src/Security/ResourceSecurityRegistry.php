<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Security;

/**
 * The type-keyed registry of declarative {@see ResourceSecurity} expression sets
 * (bundle ADR 0043). Built from a plain scalar `type → {default, create, update,
 * delete, read}` map the {@see \haddowg\JsonApiBundle\DependencyInjection\Compiler\ResourceSecurityPass}
 * assembles from each resource's `#[AsJsonApiResource(security: …)]` tag attributes
 * (the map flows through the container as scalars; value objects are not dumpable).
 *
 * Security is **type-keyed and server-independent**, like the relations registry: a
 * type that joins several servers carries one expression set. A type that declared
 * no security is absent from the map, so {@see securityFor()} returns `null` and the
 * subscriber is a no-op for it.
 */
final class ResourceSecurityRegistry
{
    /** @var array<string, ResourceSecurity> */
    private array $byType;

    /**
     * @param array<string, array{default?: string|null, create?: string|null, update?: string|null, delete?: string|null, read?: string|null}> $expressions
     */
    public function __construct(array $expressions = [])
    {
        $byType = [];
        foreach ($expressions as $type => $set) {
            $security = new ResourceSecurity(
                default: $set['default'] ?? null,
                create: $set['create'] ?? null,
                update: $set['update'] ?? null,
                delete: $set['delete'] ?? null,
                read: $set['read'] ?? null,
            );

            if (!$security->isEmpty()) {
                $byType[$type] = $security;
            }
        }

        $this->byType = $byType;
    }

    /**
     * The declared expression set for `$type`, or `null` when the type declared no
     * security (the subscriber then leaves the operation ungated by this layer).
     */
    public function securityFor(string $type): ?ResourceSecurity
    {
        return $this->byType[$type] ?? null;
    }
}
