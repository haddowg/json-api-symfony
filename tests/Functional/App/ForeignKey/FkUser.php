<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ForeignKey;

/**
 * A user served by {@see FkUserProvider}, not by Doctrine. It holds none of its related
 * rows; each relation reads them through a foreign key.
 */
final class FkUser
{
    public function __construct(
        public string $id = '',
        public string $name = '',
    ) {}
}
