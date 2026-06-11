<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

/**
 * A plain `widgets` model: the parent resource of the serialize-only witness, with
 * a to-one `color` relationship to the resource-less {@see Color} type.
 */
final class Widget
{
    public function __construct(
        public string $id = '',
        public string $name = '',
        public ?Color $color = null,
    ) {}
}
