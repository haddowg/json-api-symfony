<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\OpenApi\Serve;

/**
 * What the warmed document referenced before any serve decorator ran: the components
 * reachable from outside `components`, and the tags its operations used. Only these are
 * candidates for {@see ComponentPruner::prune()}.
 *
 * @internal
 */
final readonly class PruneBaseline
{
    /**
     * @param array<string, true> $reachable     component pointers, e.g. `#/components/schemas/Product`
     * @param array<string, true> $operationTags tag names used by at least one operation
     */
    public function __construct(
        public array $reachable,
        public array $operationTags,
    ) {}
}
