<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Hook;

use haddowg\JsonApi\Exception\AbstractJsonApiException;
use haddowg\JsonApi\Schema\Error\Error;

/**
 * The exception a lifecycle hook throws to **abort** an operation — the example's
 * witness for the before-hook escape hatch (resource methods and event
 * subscribers alike). Because it implements
 * {@see \haddowg\JsonApi\Exception\JsonApiExceptionInterface} (via
 * {@see AbstractJsonApiException}), the bundle's route-scoped
 * {@see \haddowg\JsonApiBundle\EventListener\ExceptionListener} renders it as a
 * JSON:API error document with the carried status verbatim — `403` for a
 * guard/authz refusal, `409` for a conflict, `422` for an imperative-validation
 * failure.
 *
 * The static constructors name the three abort flavours the example demonstrates:
 * {@see conflict()} (the `beforeDelete` referenced-resource guard) and
 * {@see forbidden()} (the `serving` read-only gate).
 */
final class HookAbortException extends AbstractJsonApiException
{
    private function __construct(string $message, int $status, private readonly string $errorCode)
    {
        parent::__construct($message, $status);
    }

    public static function conflict(string $message): self
    {
        return new self($message, 409, 'CONFLICT');
    }

    public static function forbidden(string $message): self
    {
        return new self($message, 403, 'FORBIDDEN');
    }

    /**
     * @return list<Error>
     */
    public function getErrors(): array
    {
        return [
            new Error(
                status: (string) $this->getStatusCode(),
                code: $this->errorCode,
                title: $this->getMessage(),
            ),
        ];
    }
}
