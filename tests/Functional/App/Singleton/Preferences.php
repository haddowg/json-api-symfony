<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Singleton;

/**
 * A user's preferences, served by a bare serializer as the `preferences` singleton.
 */
final class Preferences
{
    public function __construct(
        public string $id = '',
        public string $owner = '',
        public string $theme = '',
    ) {}
}
