<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Composite;

/**
 * The plain model behind the in-memory `checklists` type.
 */
final class Checklist
{
    /**
     * @param list<mixed>               $tags
     * @param array<string, mixed>      $settings
     * @param array<string, mixed>|null $options
     */
    public function __construct(
        public string $id = '',
        public bool $done = false,
        public int $count = 0,
        public string $title = '',
        public array $tags = [],
        public array $settings = [],
        public ?string $note = null,
        public ?array $options = null,
    ) {}
}
