<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

/**
 * The plain model backing the three multi-server witness resources (ADR 0034): an
 * id, a name, and an optional self-referential `related` link (left null — the
 * relationship exists only to render a convention `links.self` whose base_uri
 * reveals the resolved Server).
 */
final class MultiServerWidget
{
    public function __construct(
        public string $id = '',
        public string $name = '',
        public ?MultiServerWidget $related = null,
    ) {}
}
