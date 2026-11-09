<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\OpenObject;

/**
 * The plain model behind the in-memory `sheets` type.
 */
final class Sheet
{
    /**
     * @param list<mixed>|null          $rows
     * @param array<string, mixed>|null $labels
     * @param array<string, mixed>|null $settings
     * @param array<string, mixed>|null $metrics
     */
    public function __construct(
        public string $id = '',
        public string $name = '',
        public ?array $rows = null,
        public ?array $labels = null,
        public ?array $settings = null,
        public ?array $metrics = null,
    ) {}
}
