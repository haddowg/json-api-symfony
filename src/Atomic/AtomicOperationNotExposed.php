<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Atomic;

use haddowg\JsonApi\Exception\JsonApiExceptionInterface;
use haddowg\JsonApi\Schema\Error\Error;
use haddowg\JsonApiBundle\Operation\Operation;

/**
 * An atomic operation asks for a write its target type does not expose: an `add` to a
 * type without Create, an `update` without Update, a `remove` without Delete, or any
 * relationship operation on a type without Update. A batch writes only what the same
 * server routes directly, and JSON:API answers an unsupported write with `403`.
 */
final class AtomicOperationNotExposed extends \RuntimeException implements JsonApiExceptionInterface
{
    public function __construct(public readonly string $type, public readonly Operation $operation)
    {
        parent::__construct(\sprintf(
            'The JSON:API type "%s" does not expose the %s operation.',
            $type,
            $operation->value,
        ));
    }

    public function getErrors(): array
    {
        return [
            new Error(
                status: '403',
                code: 'ATOMIC_OPERATION_NOT_EXPOSED',
                title: 'Atomic operation not exposed',
                detail: $this->getMessage(),
            ),
        ];
    }

    public function getStatusCode(): int
    {
        return 403;
    }
}
