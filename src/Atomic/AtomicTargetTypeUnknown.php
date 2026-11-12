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
 * An atomic operation targets a resource type the batch cannot write — the
 * `data.type` of an `add`, or the `ref.type`/resolved `href` type of an
 * `update`/`remove`, names a type that is not registered on the batch's server, or
 * one for which no {@see \haddowg\JsonApiBundle\DataPersister\DataPersisterInterface}
 * is registered.
 *
 * Inside a batch there is no routing step to reject an unknown type first (unlike a
 * direct CRUD call, which `404`s at the router), so the executor resolves each
 * participating type's persister in its **pre-flight** scan — before opening any
 * transaction — and a type with no persister is the client's fault (a malformed or
 * unknown type), so it is a `404`, mirroring the direct-call routing miss. Raised
 * from {@see AtomicLoopBackend} *before* `begin()`, so no rollback is needed.
 */
final class AtomicTargetTypeUnknown extends \RuntimeException implements DescribedErrorInterface
{
    public function __construct(public readonly string $type)
    {
        parent::__construct(\sprintf(
            'The atomic operation targets the JSON:API type "%s", which this server does not register or no '
            . 'data persister can write.',
            $type,
        ));
    }

    public static function describe(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: 'ATOMIC_TARGET_TYPE_UNKNOWN',
            status: 404,
            title: 'Atomic operation target type is unknown',
            context: ['type' => ErrorContextType::Str],
            feature: ErrorFeature::AtomicOperations,
            scope: ErrorScope::kinds(OperationKind::Atomic),
            description: 'An operation targets a resource type this server does not register, or one it has no way to write. The server applied none of the batch. Use a type the API serves and send the batch again.',
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
