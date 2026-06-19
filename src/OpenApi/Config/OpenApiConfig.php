<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\OpenApi\Config;

use haddowg\JsonApi\OpenApi\EnumDescriptionMode;
use haddowg\JsonApiBundle\OpenApi\Metadata\ServerDocumentConfig;

/**
 * The resolved `json_api.openapi.*` configuration (design §6), built once at compile
 * time by {@see OpenApiConfigResolver} and threaded through the bundle's OpenAPI
 * wiring.
 *
 * It is a plain immutable carrier of the document-generation settings (enabled,
 * exposure, multi-server mode, enum-description mode, json path, public path) and the
 * per-server {@see ServerDocumentConfig} map (info / servers / security / tags) the
 * {@see \haddowg\JsonApiBundle\OpenApi\Metadata\MetadataSource} folds in. The bundle
 * destructures it in `loadExtension()` to set parameters + wire services; it is not a
 * runtime service.
 */
final readonly class OpenApiConfig
{
    /**
     * @param array<string, ServerDocumentConfig> $serverDocuments the per-server document config, keyed by server name
     */
    public function __construct(
        public bool $enabled,
        public bool $exposeInProd,
        public bool $combined,
        public EnumDescriptionMode $enumDescriptionMode,
        public string $jsonPath,
        public ?string $publicPath,
        public array $serverDocuments,
    ) {}
}
