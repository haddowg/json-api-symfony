<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\OpenApi;

use haddowg\JsonApi\OpenApi\EnumDescriptionMode;
use haddowg\JsonApi\OpenApi\OpenApi;
use haddowg\JsonApi\OpenApi\OpenApiProjector;
use haddowg\JsonApi\OpenApi\OperationProjector;
use haddowg\JsonApi\OpenApi\SchemaProjector;
use haddowg\JsonApiBundle\OpenApi\Metadata\MetadataSource;
use haddowg\JsonApiBundle\Server\ServerProvider;

/**
 * Builds the OpenAPI 3.1 {@see OpenApi} document for one server (design §3, D17) —
 * the bundle's pure-projection entry point: it composes the bundle
 * {@see MetadataSource} (which reads the live registry into core's metadata
 * contract) with the core {@see OpenApiProjector} (which projects that contract into
 * the document).
 *
 * The projector is configured once with the app's
 * {@see EnumDescriptionMode} (`json_api.openapi.enum_value_descriptions`) — the
 * single {@see SchemaProjector} carrying it is shared by the projector and its
 * {@see OperationProjector} so component schemas and operation-body schemas surface
 * backed-enum descriptions identically.
 *
 * Building is never per-request (D17): the {@see DocumentWarmer} pre-builds each
 * server's document at `cache:warmup` and the controller serves the artifact; this
 * factory is the build itself (the warmer's source, and the controller's dev
 * lazy-build fallback). It is **pure** — no I/O — so it is cheap to call in a test
 * and safe to memoize.
 *
 * The Slice-5 wholesale-customisation decorator (`OpenApiFactoryInterface`) is **not
 * wired here yet**; this factory is the clean seam it will decorate (a decorator
 * receives this factory's built document and returns a mutated one). Until then the
 * projection is the app's document verbatim.
 */
final class DocumentFactory
{
    private readonly OpenApiProjector $projector;

    public function __construct(
        private readonly MetadataSource $metadata,
        EnumDescriptionMode $enumDescriptionMode = EnumDescriptionMode::Both,
    ) {
        $schemaProjector = new SchemaProjector($enumDescriptionMode);
        $this->projector = new OpenApiProjector(
            $schemaProjector,
            new OperationProjector($schemaProjector),
        );
    }

    /**
     * The OpenAPI document for `$serverName` (the implicit `default` server when
     * null).
     *
     * @throws \LogicException when an unknown server name is requested (the
     *                         {@see ServerProvider} validates the name)
     */
    public function forServer(?string $serverName = null): OpenApi
    {
        $serverName ??= ServerProvider::DEFAULT_SERVER;

        return $this->projector->project($this->metadata->forServer($serverName));
    }

    /**
     * The single **combined** OpenAPI document spanning every declared server (design
     * D5, §10) — the `multi_server: combined` document, unioning every server's types,
     * advertised base URIs, tag definitions and security schemes into one document.
     *
     * @throws \LogicException when two servers declare the same JSON:API type (one
     *                         document cannot describe a type twice — see
     *                         {@see MetadataSource::combined()})
     */
    public function combined(): OpenApi
    {
        return $this->projector->project($this->metadata->combined());
    }
}
