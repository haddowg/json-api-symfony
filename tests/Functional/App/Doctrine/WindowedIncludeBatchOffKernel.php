<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

/**
 * The `json_api.doctrine.window_functions: false` kernel — the per-parent bounded
 * fallback for the windowed-include batch (bundle ADR 0065): a loop over the proven
 * single-parent fetch, each a real LIMIT push-down.
 */
final class WindowedIncludeBatchOffKernel extends WindowedIncludeBatchKernel
{
    protected function windowFunctions(): bool
    {
        return false;
    }
}
