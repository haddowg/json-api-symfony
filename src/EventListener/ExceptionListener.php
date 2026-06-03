<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\EventListener;

use haddowg\JsonApi\Exception\JsonApiExceptionInterface;
use haddowg\JsonApi\Response\ErrorResponse;
use haddowg\JsonApi\Schema\Error\Error;
use haddowg\JsonApi\Schema\Error\InternalServerError;
use haddowg\JsonApiBundle\Server\ServerProvider;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * The route-scoped `kernel.exception` listener: it owns every error on JSON:API
 * routes (it acts only when the matched route carries
 * {@see self::ROUTE_MARKER}), so even failures are spec-compliant JSON:API
 * documents.
 *
 * Mapping:
 *  - a core {@see JsonApiExceptionInterface} renders through its own
 *    `getErrors()` / `getStatusCode()` via {@see ErrorResponse::fromException()};
 *  - a Symfony {@see HttpExceptionInterface} (firewall `401`/`403`, routing
 *    `404`, …) maps to a status-keyed JSON:API error;
 *  - anything else becomes a `500`, with `{exception, file, line, trace}` in the
 *    error object's `meta` gated on `kernel.debug` and the throwable logged.
 *
 * The throwable→500 mapping delegates to core's public, stateless
 * {@see InternalServerError::for()} seam, so this listener and core's
 * `ErrorHandlerMiddleware` produce a byte-identical generic-500 error object.
 */
final class ExceptionListener
{
    public const string ROUTE_MARKER = '_jsonapi';

    public function __construct(
        private readonly ServerProvider $servers,
        private readonly PsrHttpFactory $psrHttpFactory,
        private readonly HttpFoundationFactory $httpFoundationFactory,
        private readonly bool $debug = false,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function onKernelException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();

        if ($request->attributes->get(self::ROUTE_MARKER) !== true) {
            return;
        }

        $throwable = $event->getThrowable();

        $serverName = $request->attributes->get('_jsonapi_server');
        $server = $this->servers->get(\is_string($serverName) ? $serverName : null);

        $psrRequest = $request->attributes->get(RequestListener::PSR_REQUEST_ATTRIBUTE);
        if (!$psrRequest instanceof ServerRequestInterface) {
            $psrRequest = $this->psrHttpFactory->createRequest($request);
        }

        $errorResponse = $this->toErrorResponse($throwable);

        $psrResponse = $errorResponse->toPsrResponse($server, $psrRequest);

        $event->setResponse($this->httpFoundationFactory->createResponse($psrResponse));
    }

    private function toErrorResponse(\Throwable $throwable): ErrorResponse
    {
        if ($throwable instanceof JsonApiExceptionInterface) {
            return ErrorResponse::fromException($throwable);
        }

        if ($throwable instanceof HttpExceptionInterface) {
            return ErrorResponse::fromErrors($this->httpError($throwable));
        }

        $this->logger?->error($throwable->getMessage(), ['exception' => $throwable]);

        return ErrorResponse::fromErrors(InternalServerError::for($throwable, $this->debug));
    }

    private function httpError(HttpExceptionInterface $throwable): Error
    {
        $status = $throwable->getStatusCode();

        return new Error(
            status: (string) $status,
            title: $this->reasonPhrase($status),
            detail: $this->debug ? $throwable->getMessage() : '',
        );
    }

    private function reasonPhrase(int $status): string
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
