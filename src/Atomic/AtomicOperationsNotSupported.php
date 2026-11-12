<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Atomic;

use haddowg\JsonApi\Exception\DescribedErrorInterface;
use haddowg\JsonApi\Exception\ErrorContextType;
use haddowg\JsonApi\Exception\ErrorDescriptor;
use haddowg\JsonApi\Exception\ErrorFeature;
use haddowg\JsonApi\Exception\ErrorScope;
use haddowg\JsonApi\OpenApi\Metadata\OperationKind;

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
final class AtomicOperationsNotSupported extends \RuntimeException implements DescribedErrorInterface
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

    public static function describe(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: 'ATOMIC_OPERATIONS_NOT_SUPPORTED',
            status: 403,
            title: 'Atomic operations not supported',
            context: ['type' => ErrorContextType::Str],
            feature: ErrorFeature::AtomicOperations,
            scope: ErrorScope::kinds(OperationKind::Atomic),
            description: 'A type in the batch is stored where the server cannot write it inside one transaction, so it cannot promise the batch applies all or nothing. The server applied none of the batch. Write that type in a request of its own.',
            exampleDetail: 'The JSON:API type "articles" cannot take part in an Atomic Operations batch: its data persister is not transactional. Implement TransactionalDataPersisterInterface on the persister for this type to enable all-or-nothing batched writes.',
        );
    }

    public function getErrors(): array
    {
        return [
            self::describe()->toError(
                detail: $this->getMessage(),
                context: ['type' => $this->type],
            ),
        ];
    }

    public function getStatusCode(): int
    {
        return self::describe()->status;
    }
}
