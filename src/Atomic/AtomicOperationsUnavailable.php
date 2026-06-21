<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Atomic;

use haddowg\JsonApi\Exception\JsonApiExceptionInterface;
use haddowg\JsonApi\Schema\Error\Error;

/**
 * The Atomic Operations executor could not run because its collaborators are not
 * wired — the {@see \haddowg\JsonApiBundle\DataPersister\WriteTransactionContext} or
 * the router the executor needs to resolve an `href` and defer the post-commit hooks.
 *
 * In the bundle both are always wired, so this is unreachable in a normally-built
 * application; it is the defensive error for a partially-wired handler (a stripped-down
 * programmatic test), rendered as a `500` rather than a fatal `TypeError`.
 */
final class AtomicOperationsUnavailable extends \RuntimeException implements JsonApiExceptionInterface
{
    public function __construct()
    {
        parent::__construct('Atomic Operations are not available: the executor is not fully wired.');
    }

    public function getErrors(): array
    {
        return [
            new Error(
                status: '500',
                code: 'ATOMIC_OPERATIONS_UNAVAILABLE',
                title: 'Atomic operations unavailable',
                detail: $this->getMessage(),
            ),
        ];
    }

    public function getStatusCode(): int
    {
        return 500;
    }
}
