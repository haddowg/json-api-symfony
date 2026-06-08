<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\EventListener;

use haddowg\JsonApi\Negotiation\RequestValidator;
use haddowg\JsonApi\Operation\OperationContext;
use haddowg\JsonApi\Operation\OperationFactory;
use haddowg\JsonApi\Request\JsonApiRequest;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApiBundle\Operation\TargetResolver;
use haddowg\JsonApiBundle\Server\ServerProvider;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * The front of the bundle's request lifecycle (a kernel listener, **not** core's
 * PSR-15 chain).
 *
 * On a JSON:API route (one whose route defaults carry `_jsonapi_type`) it:
 *  1. resolves the {@see \haddowg\JsonApi\Operation\Target} via the
 *     {@see TargetResolver};
 *  2. picks the server by the `_jsonapi_server` route default;
 *  3. converts the Symfony request to a PSR-7 request and wraps it in core's
 *     {@see JsonApiRequest} (the idempotent guard every core middleware uses);
 *  4. runs the negotiation + query-param validation core's middleware would,
 *     by calling {@see RequestValidator} directly (no `Middleware\*` class), plus
 *     body well-formedness + top-level-member checks on a write verb;
 *  5. builds the matching operation via core's
 *     {@see \haddowg\JsonApi\Operation\OperationFactory} (the same verb × shape
 *     dispatch the PSR-15 adapter uses) and calls `Server::dispatch()`;
 *  6. stashes the returned response value object on the request attributes for
 *     the {@see ViewListener} to render — it sets **no** Response, so
 *     `kernel.view` runs next.
 *
 * It runs after Symfony's `RouterListener` so the route defaults are populated.
 */
final class RequestListener
{
    public const string RESPONSE_ATTRIBUTE = '_jsonapi_response';

    public const string SERVER_ATTRIBUTE = '_jsonapi_resolved_server';

    public const string PSR_REQUEST_ATTRIBUTE = '_jsonapi_psr_request';

    public function __construct(
        private readonly ServerProvider $servers,
        private readonly TargetResolver $targetResolver,
        private readonly PsrHttpFactory $psrHttpFactory,
        private readonly OperationFactory $operationFactory = new OperationFactory(),
    ) {}

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        $target = $this->targetResolver->resolveFromRequest($request);
        if ($target === null) {
            return;
        }

        $serverName = $request->attributes->get('_jsonapi_server');
        $server = $this->servers->get(\is_string($serverName) ? $serverName : null);

        $psrRequest = $this->psrHttpFactory->createRequest($request);
        $jsonApiRequest = $psrRequest instanceof JsonApiRequestInterface
            ? $psrRequest
            : new JsonApiRequest($psrRequest);

        $validator = new RequestValidator();
        $validator->negotiate($jsonApiRequest);
        $validator->validateQueryParams($jsonApiRequest);

        // A write body (POST/PATCH carry one; DELETE does not) must be well-formed
        // JSON with valid top-level members before core's hydrator reads it — the
        // belt core's RequestBodyParsingMiddleware would run, called directly.
        if (\in_array($jsonApiRequest->getMethod(), ['POST', 'PATCH'], true)) {
            $validator->validateJsonBody($jsonApiRequest);
            $validator->validateTopLevelMembers($jsonApiRequest);
        }

        $operation = $this->operationFactory->fromRequest(
            $jsonApiRequest,
            $target,
            new OperationContext($server, $jsonApiRequest),
        );

        $response = $server->dispatch($operation);

        $request->attributes->set(self::RESPONSE_ATTRIBUTE, $response);
        $request->attributes->set(self::SERVER_ATTRIBUTE, $server);
        $request->attributes->set(self::PSR_REQUEST_ATTRIBUTE, $jsonApiRequest);
    }
}
