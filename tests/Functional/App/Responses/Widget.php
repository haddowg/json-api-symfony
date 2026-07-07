<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Responses;

/**
 * A trivial domain object for the per-operation response-declaration witness
 * ({@see WidgetResource}) — an id and a name, served by an
 * {@see \haddowg\JsonApiBundle\DataProvider\InMemoryDataProvider}.
 */
final class Widget
{
    public function __construct(
        public string $id,
        public string $name,
    ) {}
}
