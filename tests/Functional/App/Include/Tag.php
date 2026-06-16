<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Include;

/**
 * A plain in-memory `tags` model for the include-safeguards suite. Its to-one
 * `node` relation is includable from the `tags` root (`GET /tags/{id}?include=node`),
 * but a `roots` resource whose allowed-include-paths whitelist omits the nested
 * `node.tag.node` path forbids reaching it that way — the Capability C headline.
 */
final class Tag
{
    public function __construct(
        public string $id = '',
        public string $name = '',
        public ?Node $node = null,
    ) {}
}
