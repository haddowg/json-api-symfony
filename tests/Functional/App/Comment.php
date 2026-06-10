<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

/**
 * A plain comment model fed to the in-memory provider — one member of an
 * {@see Article}'s to-many `comments` relationship. A POPO with public scalar
 * properties, read by core's {@see \haddowg\JsonApi\Resource\Field\Accessor}.
 */
final class Comment
{
    public function __construct(
        public string $id = '',
        public string $body = '',
    ) {}
}
