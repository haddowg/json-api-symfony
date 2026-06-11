<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

/**
 * A plain `colors` model for the serialize-only witness (ADR 0024): it is only
 * ever a related/embedded value on a {@see Widget}, rendered by the standalone
 * {@see ColorSerializer}. It has no endpoints, no provider, no hydrator of its own.
 */
final class Color
{
    public function __construct(
        public string $id = '',
        public string $name = '',
    ) {}
}
