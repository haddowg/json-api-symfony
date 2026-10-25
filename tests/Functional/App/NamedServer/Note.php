<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\NamedServer;

/**
 * The resource-less `notes` model, served by a standalone serializer on both servers.
 */
final class Note
{
    public function __construct(
        public int $id,
        public string $name,
        public ?Note $author = null,
        public ?Note $editor = null,
    ) {}
}
