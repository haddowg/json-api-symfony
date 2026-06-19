<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Server;

/**
 * The per-server, per-type **route descriptor** map — the runtime-readable
 * enumeration of every JSON:API type registered for a server, with its URI segment,
 * resource/standalone shape, hydrator/relations presence, the exposed CRUD
 * operation allow-list and the OpenAPI tag refs.
 *
 * The same plain-scalar descriptor map the
 * {@see \haddowg\JsonApiBundle\DependencyInjection\Compiler\ResourceLocatorPass}
 * already hands to the {@see \haddowg\JsonApiBundle\Routing\JsonApiRouteLoader}
 * (which emits the routes from it) — surfaced here as a standalone service so the
 * OpenAPI {@see \haddowg\JsonApiBundle\OpenApi\Metadata\MetadataSource} can list a
 * server's types and read each one's uriType / operations / tags without depending
 * on the route loader. Descriptors flow as scalars (strings, bools, string lists)
 * because the compiled container cannot dump value objects.
 *
 * @phpstan-type RouteDescriptor array{uriType: string, isResource: bool, hasHydrator: bool, hasRelations: bool, operations: list<string>, tags: list<string>}
 */
final class RouteDescriptorRegistry
{
    /**
     * @param array<string, array<string, RouteDescriptor>> $descriptorsByServer keyed by server name, then by JSON:API type
     */
    public function __construct(private readonly array $descriptorsByServer = []) {}

    /**
     * The descriptor map for `$server`, keyed by JSON:API type, in registration
     * order — empty when the server exposes no types.
     *
     * @return array<string, RouteDescriptor>
     */
    public function forServer(string $server): array
    {
        return $this->descriptorsByServer[$server] ?? [];
    }

    /**
     * The descriptor for one `(server, type)`, or `null` when the type is not
     * registered for that server.
     *
     * @return RouteDescriptor|null
     */
    public function forType(string $server, string $type): ?array
    {
        return $this->descriptorsByServer[$server][$type] ?? null;
    }

    /**
     * Every server name that has a descriptor map, in registration order — the source
     * the combined-document {@see \haddowg\JsonApiBundle\OpenApi\Metadata\MetadataSource}
     * iterates to union every server's types.
     *
     * @return list<string>
     */
    public function serverNames(): array
    {
        return \array_keys($this->descriptorsByServer);
    }
}
