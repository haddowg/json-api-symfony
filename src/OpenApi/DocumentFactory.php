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
 * The wholesale-customisation decorator seam (design §5, D7 — bundle ADR 0080) is
 * applied **here**, after the core projection: every registered
 * {@see OpenApiFactoryInterface} (priority-ordered, lower first) receives the built
 * {@see OpenApi} VO and returns a mutated one. Because every build path — the warmer,
 * the controller's dev lazy-build, and the CLI export — flows through this factory,
 * decorating here covers all three uniformly; an app's decorators get the last word
 * over anything the projector produced.
 */
final class DocumentFactory
{
    private readonly OpenApiProjector $projector;

    /** @var list<OpenApiFactoryInterface> */
    private readonly array $decorators;

    /**
     * @param iterable<OpenApiFactoryInterface> $decorators the registered decorators, as ordered by
     *                                                      the tagged iterator (Symfony yields them
     *                                                      highest priority first); reversed here so
     *                                                      they are **applied** lowest priority first
     *                                                      and the highest-priority decorator gets the
     *                                                      final mutation (the bundle's highest-wins
     *                                                      convention)
     */
    public function __construct(
        private readonly MetadataSource $metadata,
        EnumDescriptionMode $enumDescriptionMode = EnumDescriptionMode::Both,
        iterable $decorators = [],
    ) {
        $schemaProjector = new SchemaProjector($enumDescriptionMode);
        $this->projector = new OpenApiProjector(
            $schemaProjector,
            new OperationProjector($schemaProjector),
        );
        // The tagged iterator yields highest priority first; reverse so applying in
        // foreach order means the highest-priority decorator runs LAST and gets the
        // final word (consistent with the provider/persister/mapper highest-wins
        // convention elsewhere in the bundle).
        $ordered = \is_array($decorators) ? \array_values($decorators) : \iterator_to_array($decorators, false);
        $this->decorators = \array_reverse($ordered);
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

        return $this->decorate(
            $this->projector->project($this->metadata->forServer($serverName)),
            $serverName,
        );
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
        return $this->decorate(
            $this->projector->project($this->metadata->combined()),
            \haddowg\JsonApiBundle\Controller\OpenApiController::COMBINED_KEY,
        );
    }

    /**
     * Runs the built document through every registered decorator applied in ascending
     * priority order (lower priority first; the highest-priority decorator applied last
     * gets the final word — the iterator is reversed in the constructor to achieve this).
     */
    private function decorate(OpenApi $document, string $server): OpenApi
    {
        foreach ($this->decorators as $decorator) {
            $document = $decorator->decorate($document, $server);
        }

        return $document;
    }
}
