<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Server;

use haddowg\JsonApi\Exception\NoResourceRegistered;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Server\Server;

/**
 * Resolves a registered type's declarative metadata (its resource, its declared
 * relations) from a {@see Server}, tolerating a type that has **no**
 * {@see AbstractResource}: a bare serializer/hydrator pair registered through
 * core's {@see Server::registerSerializerHydrator()} declares no field inventory,
 * so it has no resource, no relations, no filter/sort vocabulary and no paginator.
 *
 * It collapses the
 * `try { $server->resources()->resourceFor($type) } catch (NoResourceRegistered)`
 * dance the generic CRUD engine would otherwise repeat at every
 * metadata-dependent call site into one resource-presence-aware lookup, so the
 * engine stays generic over both a full resource and a bare pair without per-type
 * branching — the capstone seam the bare-pair path (bundle ADR 0021) plugs into.
 *
 * The {@see Server} is passed per call (it flows from the operation context, and
 * the architecture is multi-server-capable) rather than held, so the resolver is
 * a stateless, dependency-free service.
 */
final class TypeMetadataResolver
{
    /**
     * The resource registered for `$type`, or `null` when the type is a bare
     * serializer/hydrator pair (no field inventory). Never throws.
     */
    public function resourceFor(Server $server, string $type): ?AbstractResource
    {
        try {
            return $server->resources()->resourceFor($type);
        } catch (NoResourceRegistered) {
            return null;
        }
    }

    /**
     * The declared, non-hidden relation named `$name` on `$type`'s resource, or
     * `null` when the type has no resource (a bare pair) or no such relationship.
     */
    public function relationNamed(Server $server, string $type, string $name): ?RelationInterface
    {
        return $this->resourceFor($server, $type)?->relationNamed($name);
    }
}
