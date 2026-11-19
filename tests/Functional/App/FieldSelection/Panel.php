<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\FieldSelection;

/**
 * The plain model behind the in-memory `panels` type.
 */
final class Panel
{
    /**
     * @param array<string, mixed>|null $values
     * @param array<string, mixed>|null $groups
     * @param array<string, mixed>|null $settings
     */
    public function __construct(
        public string $id = '',
        public string $name = '',
        public ?array $values = null,
        public ?array $groups = null,
        public ?array $settings = null,
        public ?Curator $curator = null,
    ) {}
}
