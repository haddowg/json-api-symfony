<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

/**
 * The in-memory `countries` model of the flattened-attribute (`on()`) conformance
 * fixture (bundle ADR 0085): the SECOND hop the book's multi-hop
 * `on('author.country')` walks to. A {@see FlattenAuthor}'s HIDDEN to-one `country`
 * relation points at it; the multi-hop eager walk loads it level-by-level (load
 * authors across the books, then load each author's country across those authors) and
 * it NEVER renders as a relationship. A POPO read by core's
 * {@see \haddowg\JsonApi\Resource\Field\Accessor}.
 */
final class FlattenCountry
{
    public function __construct(
        public ?int $id = null,
        public string $name = '',
    ) {}
}
