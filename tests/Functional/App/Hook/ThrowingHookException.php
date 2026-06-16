<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Hook;

use haddowg\JsonApi\Exception\AbstractJsonApiException;
use haddowg\JsonApi\Schema\Error\Error;

/**
 * The {@see \haddowg\JsonApi\Exception\JsonApiExceptionInterface} a before-hook
 * (or a serving subscriber) throws to abort the operation. It carries a
 * configurable status so the suite can assert that the route-scoped
 * {@see \haddowg\JsonApiBundle\EventListener\ExceptionListener} renders the thrown
 * status verbatim — `403` for a guard/authz abort, `422` for an imperative
 * validation failure, `409` for a conflict.
 */
final class ThrowingHookException extends AbstractJsonApiException
{
    public function __construct(private readonly int $status)
    {
        parent::__construct('Aborted by lifecycle hook', $status);
    }

    /**
     * @return list<Error>
     */
    public function getErrors(): array
    {
        return [
            new Error(
                status: (string) $this->status,
                code: 'HOOK_ABORTED',
                title: 'Aborted by lifecycle hook',
            ),
        ];
    }
}
