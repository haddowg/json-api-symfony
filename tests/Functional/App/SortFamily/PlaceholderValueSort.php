<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\SortFamily;

use haddowg\JsonApi\Resource\Sort\KeysetSortInterface;

/**
 * Orders campaigns by the numeric value of one placeholder, the sort the `value`
 * family resolves `value.<placeholder>` to.
 */
final readonly class PlaceholderValueSort implements KeysetSortInterface
{
    public function __construct(
        public string $key,
        public string $placeholder,
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    public function keysetValue(mixed $row): ?int
    {
        \assert($row instanceof HasPlaceholderValues);
        $value = $row->placeholderValue($this->placeholder);

        return $value !== null ? (int) $value : null;
    }
}
