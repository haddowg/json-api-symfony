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
     * @param array<string, mixed>|null $address
     * @param list<mixed>|null          $rows
     * @param list<mixed>|null          $archive
     * @param list<string>|null         $tags
     * @param list<mixed>|null          $blocks
     */
    public function __construct(
        public string $id = '',
        public string $name = '',
        public ?array $values = null,
        public ?array $groups = null,
        public ?array $settings = null,
        public ?Curator $curator = null,
        public ?array $address = null,
        public ?array $rows = null,
        public ?array $archive = null,
        public ?array $tags = null,
        public ?array $blocks = null,
    ) {}
}
