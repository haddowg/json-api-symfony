<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\OpenApi\Serve;

use haddowg\JsonApi\OpenApi\OpenApi;

/**
 * The warmed document as value objects plus its {@see PruneBaseline}, PHP-serialized next
 * to the JSON artifact so a decorated request skips both the build and a JSON-to-VO
 * hydration (bundle ADR 0131).
 *
 * @internal
 */
final readonly class ServeArtifact
{
    public function __construct(
        public OpenApi $document,
        public PruneBaseline $baseline,
    ) {}

    public static function of(OpenApi $document): self
    {
        return new self($document, ComponentPruner::baseline($document->toJson()));
    }
}
