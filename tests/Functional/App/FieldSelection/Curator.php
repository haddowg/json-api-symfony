<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\FieldSelection;

/**
 * The plain model behind the in-memory `curators` type.
 */
final class Curator
{
    /**
     * @param array<string, mixed>|null $stats
     * @param array<string, mixed>|null $office
     * @param list<mixed>|null          $exhibits
     */
    public function __construct(
        public string $id = '',
        public string $name = '',
        public ?array $stats = null,
        public ?array $office = null,
        public ?array $exhibits = null,
    ) {}
}
