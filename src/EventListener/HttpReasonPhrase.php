<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\EventListener;

/**
 * The bundle's status → reason-phrase table, shared by the
 * {@see ExceptionListener} (for a Symfony `HttpExceptionInterface` / Security
 * status error) and the config-driven {@see ConfiguredExceptionMapper} (for a
 * `json_api.exceptions` status), so both produce an identically-titled error
 * object for the same status.
 */
final class HttpReasonPhrase
{
    public static function of(int $status): string
    {
        return match ($status) {
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            406 => 'Not Acceptable',
            409 => 'Conflict',
            415 => 'Unsupported Media Type',
            422 => 'Unprocessable Entity',
            default => $status >= 500 ? 'Server Error' : 'Error',
        };
    }
}
