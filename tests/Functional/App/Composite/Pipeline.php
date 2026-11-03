<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Composite;

/**
 * The plain model behind the in-memory `pipelines` type.
 */
final class Pipeline
{
    /**
     * @param list<mixed>|null $steps
     * @param list<mixed>|null $stops
     */
    public function __construct(
        public string $id = '',
        public string $name = '',
        public ?array $steps = null,
        public ?array $stops = null,
    ) {}
}
