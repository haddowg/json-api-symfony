<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

/**
 * The `json_api.doctrine.window_functions: true` kernel — the bounded ROW_NUMBER/COUNT
 * OVER native windowed-include batch.
 */
final class WindowedIncludeBatchOnKernel extends WindowedIncludeBatchKernel
{
    protected function windowFunctions(): bool
    {
        return true;
    }
}
