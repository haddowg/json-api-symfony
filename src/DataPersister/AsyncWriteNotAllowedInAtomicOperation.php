<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\DataPersister;

use haddowg\JsonApi\Exception\DescribedErrorInterface;
use haddowg\JsonApi\Exception\ErrorDescriptor;
use haddowg\JsonApi\Exception\ErrorFeature;
use haddowg\JsonApi\Exception\ErrorScope;
use haddowg\JsonApi\OpenApi\Metadata\OperationKind;

/**
 * A persister returned an {@see AcceptedForProcessing} marker while an Atomic
 * Operations batch was in flight. Async accept is incompatible with the batch's
 * all-or-nothing synchronous commit — a `202` deferred write cannot participate in
 * a transaction the batch commits or rolls back at the end — so the offending
 * sub-operation fails and the whole batch rolls back.
 */
final class AsyncWriteNotAllowedInAtomicOperation extends \RuntimeException implements DescribedErrorInterface
{
    public function __construct()
    {
        parent::__construct('A write accepted for asynchronous processing cannot participate in an Atomic Operations batch.');
    }

    public static function describe(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: 'ASYNC_WRITE_IN_ATOMIC_OPERATION',
            status: 422,
            title: 'Async write not allowed in atomic operation',
            feature: ErrorFeature::AtomicOperations,
            scope: ErrorScope::kinds(OperationKind::Atomic),
            description: 'An operation in the batch is a write the server accepts only for processing later, which cannot take part in a batch that applies all or nothing. The server applied none of the batch. Send that write in a request of its own.',
            exampleDetail: 'A write accepted for asynchronous processing cannot participate in an Atomic Operations batch.',
        );
    }

    public function getErrors(): array
    {
        return [
            self::describe()->toError(detail: $this->getMessage()),
        ];
    }

    public function getStatusCode(): int
    {
        return self::describe()->status;
    }
}
