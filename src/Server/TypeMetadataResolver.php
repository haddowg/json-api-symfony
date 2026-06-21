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
 * `try { $server->resourceFor($type) } catch (NoResourceRegistered)`
 * dance the generic CRUD engine would otherwise repeat at every
 * metadata-dependent call site into one resource-presence-aware lookup, so the
 * engine stays generic over both a full resource and a bare pair without per-type
 * branching — the capstone seam the bare-pair path (bundle ADR 0021) plugs into.
 *
 * The {@see Server} is passed per call (it flows from the operation context, and
 * the architecture is multi-server-capable) rather than held; relations are sourced
 * resource-first then from the type-keyed {@see RelationsRegistry}, so a
 * resource-less type that declared standalone relations (ADR 0026) resolves the same
 * way as a resource.
 */
final class TypeMetadataResolver
{
    public function __construct(private readonly RelationsRegistry $relations) {}

    /**
     * The resource registered for `$type`, or `null` when the type is a bare
     * serializer/hydrator pair (no field inventory). Never throws.
     */
    public function resourceFor(Server $server, string $type): ?AbstractResource
    {
        try {
            return $server->resourceFor($type);
        } catch (NoResourceRegistered) {
            return null;
        }
    }

    /**
     * The declared, non-hidden relation named `$name` on `$type`, resolved
     * resource-first (an {@see AbstractResource}'s own relations) then from the
     * standalone {@see RelationsRegistry} for a resource-less type — or `null` when
     * neither declares a relation of that name.
     */
    public function relationNamed(Server $server, string $type, string $name): ?RelationInterface
    {
        $relation = $this->resourceFor($server, $type)?->relationNamed($name);
        if ($relation !== null) {
            return $relation;
        }

        foreach ($this->relations->relationsFor($type) ?? [] as $candidate) {
            if ($candidate->name() === $name) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The declared relation named `$name` on `$type` **including hidden relations**,
     * resolved resource-first (an {@see AbstractResource}'s own field-inventory
     * relations, hidden or not) then from the standalone {@see RelationsRegistry} —
     * or `null` when neither declares a relation of that name.
     *
     * The hidden-inclusive twin of {@see relationNamed()} (and core's own
     * {@see AbstractResource::relationNamed()}, which both filter hidden out): the
     * eager-load executor resolves an `on()` attribute's backing relation here so a
     * `hidden()` "internal association" — the idiomatic backing for a flattened
     * attribute that never renders as a relationship — is still found and loaded.
     */
    public function relationNamedIncludingHidden(Server $server, string $type, string $name): ?RelationInterface
    {
        $relation = $this->resourceFor($server, $type)?->relationNamedIncludingHidden($name);
        if ($relation !== null) {
            return $relation;
        }

        foreach ($this->relations->relationsFor($type) ?? [] as $candidate) {
            if ($candidate->name() === $name) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Every declared, non-hidden relation on `$type`, resolved resource-first (an
     * {@see AbstractResource}'s own field-inventory relations) then from the
     * standalone {@see RelationsRegistry} for a resource-less type — an empty list
     * when the type is a bare serializer/hydrator pair that declares neither.
     *
     * The single enumeration the include-preloader walks to decide what to
     * batch-load; it stays in this seam so the bare-pair path (no resource, no
     * field inventory) is tolerated without per-call-site branching.
     *
     * @return list<RelationInterface>
     */
    public function relationsFor(Server $server, string $type): array
    {
        $resource = $this->resourceFor($server, $type);
        if ($resource !== null) {
            $relations = [];
            foreach ($resource->fields() as $field) {
                if ($field instanceof RelationInterface && !$field->isHidden()) {
                    $relations[] = $field;
                }
            }

            return $relations;
        }

        return $this->relations->relationsFor($type) ?? [];
    }
}
