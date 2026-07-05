<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Async;

/**
 * A minimal job resource representing an accepted-but-not-yet-processed write — the
 * thing an {@see \haddowg\JsonApiBundle\DataPersister\AcceptedForProcessing} points a
 * client at to poll. Rendered through {@see JobSerializer} as the `202` body.
 */
final class Job
{
    public function __construct(
        public string $id,
        public string $status,
    ) {}
}
