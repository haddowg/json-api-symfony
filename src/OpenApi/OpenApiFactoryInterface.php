<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\OpenApi;

use haddowg\JsonApi\OpenApi\OpenApi;

/**
 * The wholesale-customisation decorator seam (design §5, D7) — the app's last word over
 * the generated OpenAPI document.
 *
 * Inline authoring (`->description()`/`->example()` on the core builders) and config
 * (`info` / `servers` / `security` / `tags`) cover the common cases; this interface is
 * the escape hatch for anything the projection cannot express declaratively — adding a
 * server variable, an extra security scheme, per-individual-CRUD-operation tags,
 * vendor extensions, hand-written examples, or rewriting any part of the document.
 *
 * A service implementing this interface is autoconfigured onto the
 * {@see \haddowg\JsonApiBundle\JsonApiBundle::OPENAPI_FACTORY_TAG} tag and composed by
 * the {@see DocumentFactory} **after** the core projection, in ascending
 * `priority` (lower priority runs first; the highest-priority decorator gets the final
 * mutation). Because every build path — the `cache:warmup` warmer, the controller's dev
 * lazy-build, and the CLI export — goes through {@see DocumentFactory}, a decorator runs
 * for all three uniformly.
 *
 * Decorators receive the built immutable {@see OpenApi} VO and **return** a (typically
 * `with*`-derived) mutated one; they must not assume any particular ordering relative to
 * other decorators beyond the priority contract.
 */
interface OpenApiFactoryInterface
{
    /**
     * Mutate (or pass through) the document built for `$server`.
     *
     * @param OpenApi $document the freshly projected document (or the result of a
     *                          lower-priority decorator)
     * @param string  $server   the server name the document was built for — the implicit
     *                          `default` server, a named server, or the combined-mode
     *                          token {@see \haddowg\JsonApiBundle\Controller\OpenApiController::COMBINED_KEY}
     *
     * @return OpenApi the document to serve / warm / export (return `$document` unchanged
     *                 to opt out for a given server)
     */
    public function decorate(OpenApi $document, string $server): OpenApi;
}
