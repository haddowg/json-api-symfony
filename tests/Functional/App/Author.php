<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

/**
 * A plain author model fed to the in-memory provider — the related resource a
 * {@see Article}'s to-one `author` relationship points at. A POPO with public
 * scalar properties, read by core's
 * {@see \haddowg\JsonApi\Resource\Field\Accessor} the same way as {@see Article}.
 */
final class Author
{
    public function __construct(
        public string $id = '',
        public string $name = '',
    ) {}
}
