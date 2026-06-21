<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Atomic;

use haddowg\JsonApi\Exception\JsonApiExceptionInterface;
use haddowg\JsonApi\Schema\Error\Error;

/**
 * A type participating in an Atomic Operations batch cannot transact: its
 * {@see \haddowg\JsonApiBundle\DataPersister\DataPersisterInterface} does not
 * implement {@see \haddowg\JsonApiBundle\DataPersister\TransactionalDataPersisterInterface},
 * so the executor cannot guarantee all-or-nothing for the batch.
 *
 * The executor raises this in its **pre-flight** scan — before opening any
 * transaction or applying any write — so a batch touching a non-transactional type
 * is refused cleanly (`403 Forbidden`) and can never leave a partial, non-rolled-back
 * change behind. It is a {@see JsonApiExceptionInterface}, so the route-scoped
 * exception listener renders it as a JSON:API error document; because it is thrown
 * from {@see AtomicLoopBackend} *before* `begin()`, no rollback is needed.
 */
final class AtomicOperationsNotSupported extends \RuntimeException implements JsonApiExceptionInterface
{
    public function __construct(public readonly string $type)
    {
        parent::__construct(\sprintf(
            'The JSON:API type "%s" cannot take part in an Atomic Operations batch: its data persister is '
            . 'not transactional. Implement TransactionalDataPersisterInterface on the persister for this type '
            . 'to enable all-or-nothing batched writes.',
            $type,
        ));
    }

    public function getErrors(): array
    {
        return [
            new Error(
                status: '403',
                code: 'ATOMIC_OPERATIONS_NOT_SUPPORTED',
                title: 'Atomic operations not supported',
                detail: $this->getMessage(),
            ),
        ];
    }

    public function getStatusCode(): int
    {
        return 403;
    }
}
