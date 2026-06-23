<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

/**
 * A plain catalogue model fed to the in-memory provider behind the read-only
 * `catalogues` type — the {@see \haddowg\JsonApiBundle\Attribute\AsJsonApiResource}
 * `readOnly` shorthand witness. A POPO with public scalar properties read by core's
 * {@see \haddowg\JsonApi\Resource\Field\Accessor}.
 */
final class Catalogue
{
    public function __construct(
        public ?int $id = null,
        public string $name = '',
    ) {}
}
