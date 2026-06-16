<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

/**
 * A plain `books` model for the uriType witness: its JSON:API type is `book` but
 * its resource routes/links at the `books` segment (ADR 0022). A POPO with a
 * self-referential to-one `related`, so the rendered relationship's convention
 * links can be asserted to use the URI segment.
 */
final class Book
{
    public function __construct(
        public string $id = '',
        public string $title = '',
        public ?Book $related = null,
    ) {}
}
