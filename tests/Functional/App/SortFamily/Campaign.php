<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\SortFamily;

final class Campaign implements HasPlaceholderValues
{
    /**
     * @param array<string, string> $values placeholder name => stored text
     */
    public function __construct(
        public ?int $id = null,
        public string $name = '',
        public array $values = [],
    ) {}

    public function placeholderValue(string $name): ?string
    {
        return $this->values[$name] ?? null;
    }
}
