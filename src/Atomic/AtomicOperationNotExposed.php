<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Atomic;

use haddowg\JsonApi\Exception\DescribedErrorInterface;
use haddowg\JsonApi\Exception\ErrorContextType;
use haddowg\JsonApi\Exception\ErrorDescriptor;
use haddowg\JsonApi\Exception\ErrorFeature;
use haddowg\JsonApi\Exception\ErrorScope;
use haddowg\JsonApi\OpenApi\Metadata\OperationKind;
use haddowg\JsonApiBundle\Operation\Operation;

/**
 * An atomic operation asks for a write its target type does not expose: an `add` to a
 * type without Create, an `update` without Update, a `remove` without Delete, or any
 * relationship operation on a type without Update. A batch writes only what the same
 * server routes directly, and JSON:API answers an unsupported write with `403`.
 */
final class AtomicOperationNotExposed extends \RuntimeException implements DescribedErrorInterface
{
    public function __construct(public readonly string $type, public readonly Operation $operation)
    {
        parent::__construct(\sprintf(
            'The JSON:API type "%s" does not expose the %s operation.',
            $type,
            $operation->value,
        ));
    }

    public static function describe(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: 'ATOMIC_OPERATION_NOT_EXPOSED',
            status: 403,
            title: 'Atomic operation not exposed',
            context: ['type' => ErrorContextType::Str, 'operation' => ErrorContextType::Str],
            feature: ErrorFeature::AtomicOperations,
            scope: ErrorScope::kinds(OperationKind::Atomic),
            description: 'An operation asks for a write its target type does not offer on this server: an `add` to a type that cannot be created, an `update` of one that cannot be updated, a `remove` of one that cannot be deleted, or a relationship operation on a type that cannot be updated. A batch makes only the writes the API exposes directly. The server applied none of the batch. Drop the operation and send the batch again.',
        );
    }

    public function getErrors(): array
    {
        return [
            self::describe()->toError(
                detail: $this->getMessage(),
                context: ['type' => $this->type, 'operation' => $this->operation->value],
            ),
        ];
    }

    public function getStatusCode(): int
    {
        return self::describe()->status;
    }
}
