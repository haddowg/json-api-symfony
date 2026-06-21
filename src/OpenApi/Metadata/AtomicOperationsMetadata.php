<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\OpenApi\Metadata;

use haddowg\JsonApi\OpenApi\Metadata\AtomicOperationsMetadataInterface;
use haddowg\JsonApi\OpenApi\SecurityRequirement;

/**
 * The bundle's implementation of core's {@see AtomicOperationsMetadataInterface} —
 * the endpoint-shaped metadata for a server's JSON:API **Atomic Operations**
 * extension endpoint (the opt-in `POST {path}` batch endpoint).
 *
 * Built by the {@see MetadataSource} from the global `json_api.atomic_operations.*`
 * config (the same `enabled`/`path` params the {@see \haddowg\JsonApiBundle\Routing\JsonApiRouteLoader}
 * reads) and carried on each server's {@see ServerMetadata} when the extension is
 * enabled. The extension is a **single global flag** but the endpoint exists *per
 * server* (mirroring the route loader, which emits one `POST {path}` per server), so
 * every server's document carries this metadata when atomic is globally enabled.
 *
 * **Security**: the atomic endpoint carries no per-endpoint security of its own — it
 * is subject to the document-level default security like any other write. So
 * {@see security()} returns an empty list and core's projector falls back to the
 * document-level default (`ServerMetadata::defaultSecurity()`); that is the same
 * default-security modelling the rest of the document uses (design §4.6/D8).
 */
final readonly class AtomicOperationsMetadata implements AtomicOperationsMetadataInterface
{
    /**
     * The default OpenAPI tag the atomic operation is grouped under.
     */
    public const string DEFAULT_TAG = 'Atomic Operations';

    /**
     * @param list<SecurityRequirement> $security the OR-ed security alternatives for the atomic operation; empty inherits the document-level default
     */
    public function __construct(
        private string $path,
        private string $tag = self::DEFAULT_TAG,
        private array $security = [],
    ) {}

    public function path(): string
    {
        return $this->path;
    }

    public function tag(): string
    {
        return $this->tag;
    }

    public function security(): array
    {
        return $this->security;
    }
}
