<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

/**
 * A plain `ledgers` model for the operation-exposure witness (ADR 0025): a
 * read-only resource exposing only {@see \haddowg\JsonApiBundle\Operation\Operation::FetchCollection}
 * and {@see \haddowg\JsonApiBundle\Operation\Operation::FetchOne}, so its routes
 * are GET-only and POST/PATCH/DELETE are unrouted.
 */
final class Ledger
{
    public function __construct(
        public string $id = '',
        public string $name = '',
    ) {}
}
