<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataPersister;

use haddowg\JsonApi\Exception\JsonApiExceptionInterface;
use haddowg\JsonApi\Schema\Error\Error;

/**
 * A persister returned an {@see AcceptedForProcessing} marker while an Atomic
 * Operations batch was in flight. Async accept is incompatible with the batch's
 * all-or-nothing synchronous commit — a `202` deferred write cannot participate in
 * a transaction the batch commits or rolls back at the end — so the offending
 * sub-operation fails and the whole batch rolls back (bundle ADR 0110).
 */
final class AsyncWriteNotAllowedInAtomicOperation extends \RuntimeException implements JsonApiExceptionInterface
{
    public function __construct()
    {
        parent::__construct('A write accepted for asynchronous processing cannot participate in an Atomic Operations batch.');
    }

    public function getErrors(): array
    {
        return [
            new Error(
                status: '422',
                code: 'ASYNC_WRITE_IN_ATOMIC_OPERATION',
                title: 'Async write not allowed in atomic operation',
                detail: $this->getMessage(),
            ),
        ];
    }

    public function getStatusCode(): int
    {
        return 422;
    }
}
