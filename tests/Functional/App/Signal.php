<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

/**
 * A plain `signals` model for the operation-exposure witness (ADR 0025): a
 * create-only resource exposing only {@see \haddowg\JsonApiBundle\Operation\Operation::Create},
 * so only `POST /signals` is routed. Every property defaults to the empty string
 * so the in-memory persister can construct a blank instance to hydrate on create.
 */
final class Signal
{
    public function __construct(
        public string $id = '',
        public string $name = '',
    ) {}
}
