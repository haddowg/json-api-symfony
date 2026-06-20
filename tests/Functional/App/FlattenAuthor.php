<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

/**
 * The in-memory `authors` model of the flattened-attribute (`on()`) conformance
 * fixture (bundle ADR 0085): a {@see FlattenBook}'s HIDDEN to-one `author`
 * relation points at it, and the book's `authorName` attribute flattens this
 * model's `name`. A write of `authorName` mutates THIS object's `name` in place —
 * the in-memory store shares the reference, so a re-fetch of the author sees the
 * change. A POPO read by core's {@see \haddowg\JsonApi\Resource\Field\Accessor}.
 *
 * It also carries a HIDDEN to-one `country` (the second hop the book's multi-hop
 * `on('author.country')` walks to): a load-not-render relation that never renders,
 * materialised level-by-level by the multi-hop eager walk.
 */
final class FlattenAuthor
{
    public function __construct(
        public ?int $id = null,
        public string $name = '',
        public ?FlattenCountry $country = null,
    ) {}
}
