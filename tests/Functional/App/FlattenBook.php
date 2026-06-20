<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

/**
 * The in-memory `books` model of the flattened-attribute (`on()`) conformance
 * fixture (bundle ADR 0085). It carries a HIDDEN backing to-one, a sibling to-one
 * and a VISIBLE to-one:
 *
 *  - `author` — the HIDDEN backing of the flattened `authorName` attribute
 *    (`on('author')`) AND the first hop of the multi-hop `authorCountry`
 *    (`on('author.country')`): a read flattens `author.name` / `author.country.name`,
 *    a write of `authorName` mutates the loaded author's `name`;
 *  - `publisher` — backs NO rendered attribute (a sibling registered type, populated
 *    but not flattened or pinned);
 *  - `editor` — a VISIBLE to-one backing the flattened `editorName` attribute
 *    (`on('editor')`): because it renders as a relationship it can carry linkage in
 *    a write body, so a same-body write (associate `editor` + set `editorName`)
 *    witnesses the relationship-before-flatten hydration order.
 *
 * `author` is eager-loaded yet NEVER renders as a relationship (it is `hidden()`).
 * A POPO read by core's {@see \haddowg\JsonApi\Resource\Field\Accessor}.
 */
final class FlattenBook
{
    public function __construct(
        public ?int $id = null,
        public string $title = '',
        public ?FlattenAuthor $author = null,
        public ?FlattenPublisher $publisher = null,
        public ?FlattenAuthor $editor = null,
    ) {}
}
