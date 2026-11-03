<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\SortFamily;

/**
 * A campaign as both data layers model it, so {@see PlaceholderValueSort} reads a
 * placeholder's value off either.
 */
interface HasPlaceholderValues
{
    /**
     * The stored text of placeholder `$name`, or null when the campaign has none.
     */
    public function placeholderValue(string $name): ?string;
}
