<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

/**
 * A plain `tags` model fed to the in-memory provider — the genericity witness's
 * domain object (the capstone proof that a brand-new type needs no per-type
 * handler code; ADR 0021). A POPO with public scalar properties plus an optional
 * to-one `article`, read by core's
 * {@see \haddowg\JsonApi\Resource\Field\Accessor} exactly as {@see Article} is.
 */
final class Tag
{
    public function __construct(
        public ?int $id = null,
        public string $name = '',
        public ?Article $article = null,
    ) {}
}
