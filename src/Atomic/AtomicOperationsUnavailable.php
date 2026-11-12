<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Atomic;

use haddowg\JsonApi\Exception\DescribedErrorInterface;
use haddowg\JsonApi\Exception\ErrorDescriptor;
use haddowg\JsonApi\Exception\ErrorFeature;
use haddowg\JsonApi\Exception\ErrorScope;
use haddowg\JsonApi\OpenApi\Metadata\OperationKind;

/**
 * The Atomic Operations executor could not run because its collaborators are not
 * wired — the {@see \haddowg\JsonApiBundle\DataPersister\WriteTransactionContext} or
 * the router the executor needs to resolve an `href` and defer the post-commit hooks.
 *
 * In the bundle both are always wired, so this is unreachable in a normally-built
 * application; it is the defensive error for a partially-wired handler (a stripped-down
 * programmatic test), rendered as a `500` rather than a fatal `TypeError`.
 */
final class AtomicOperationsUnavailable extends \RuntimeException implements DescribedErrorInterface
{
    public function __construct()
    {
        parent::__construct('Atomic Operations are not available: the executor is not fully wired.');
    }

    public static function describe(): ErrorDescriptor
    {
        return new ErrorDescriptor(
            code: 'ATOMIC_OPERATIONS_UNAVAILABLE',
            status: 500,
            title: 'Atomic operations unavailable',
            feature: ErrorFeature::AtomicOperations,
            scope: ErrorScope::kinds(OperationKind::Atomic),
            description: 'The server could not run the batch because its Atomic Operations support is not fully set up. The fault is on the server\'s side, so retrying won\'t help; report it to the API\'s maintainers.',
            exampleDetail: 'Atomic Operations are not available: the executor is not fully wired.',
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
