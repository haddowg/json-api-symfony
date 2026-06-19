<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\OpenApi\Config;

use haddowg\JsonApiBundle\OpenApi\Metadata\ServerDocumentConfig;

/**
 * Builds the per-server {@see ServerDocumentConfig} map (info / advertised servers /
 * security schemes / tag definitions) at **runtime** from the scalar
 * `haddowg_json_api.openapi` parameter — the bridge that keeps the OAS value objects
 * out of the compiled container.
 *
 * The compiled container cannot dump a value object as a service argument, so the
 * resolved `json_api.openapi.*` config is stored as a pure-scalar parameter and this
 * provider turns it into the typed {@see ServerDocumentConfig} graph on boot, where
 * it is injected (as `$configByServer`) into the
 * {@see \haddowg\JsonApiBundle\OpenApi\Metadata\MetadataSource} via a service factory.
 */
final class ServerDocumentConfigProvider
{
    /**
     * @param array<string, mixed> $openApiConfig the resolved `json_api.openapi.*` config (scalars only)
     * @param list<string>         $servers       the declared server names
     */
    public function __construct(
        private readonly OpenApiConfigResolver $resolver,
        private readonly array $openApiConfig,
        private readonly array $servers,
    ) {}

    /**
     * The per-server document config, keyed by server name.
     *
     * @return array<string, ServerDocumentConfig>
     */
    public function map(): array
    {
        return $this->resolver->resolve(['openapi' => $this->openApiConfig], $this->servers)->serverDocuments;
    }
}
