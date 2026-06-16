<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Include;

/**
 * A plain in-memory model shared by the two root-scoped include-safeguard
 * witnesses — `roots` (an allowed-include-paths whitelist) and `caps` (a
 * per-resource max-depth override). Both wrap a single to-one `node` into the
 * `nodes` chain, so the root-scoped Capability C / Capability B checks can be
 * evaluated against a `node.*` nested path whose hops are independently includable
 * from `nodes`' own root.
 */
final class Holder
{
    public function __construct(
        public string $id = '',
        public string $label = '',
        public ?Node $node = null,
    ) {}
}
