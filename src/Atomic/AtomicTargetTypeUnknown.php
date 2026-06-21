<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Atomic;

use haddowg\JsonApi\Exception\JsonApiExceptionInterface;
use haddowg\JsonApi\Schema\Error\Error;

/**
 * An atomic operation targets a resource type no data source can write — the
 * `data.type` of an `add`, or the `ref.type`/resolved `href` type of an
 * `update`/`remove`, names a type for which no {@see \haddowg\JsonApiBundle\DataPersister\DataPersisterInterface}
 * is registered.
 *
 * Inside a batch there is no routing step to reject an unknown type first (unlike a
 * direct CRUD call, which `404`s at the router), so the executor resolves each
 * participating type's persister in its **pre-flight** scan — before opening any
 * transaction — and a type with no persister is the client's fault (a malformed or
 * unknown type), so it is a `404`, mirroring the direct-call routing miss. Raised
 * from {@see AtomicLoopBackend} *before* `begin()`, so no rollback is needed.
 */
final class AtomicTargetTypeUnknown extends \RuntimeException implements JsonApiExceptionInterface
{
    public function __construct(public readonly string $type)
    {
        parent::__construct(\sprintf(
            'The atomic operation targets the JSON:API type "%s", which no data source can write: no data '
            . 'persister is registered for it.',
            $type,
        ));
    }

    public function getErrors(): array
    {
        return [
            new Error(
                status: '404',
                code: 'ATOMIC_TARGET_TYPE_UNKNOWN',
                title: 'Atomic operation target type is unknown',
                detail: $this->getMessage(),
            ),
        ];
    }

    public function getStatusCode(): int
    {
        return 404;
    }
}
