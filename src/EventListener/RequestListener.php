<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\EventListener;

use haddowg\JsonApi\Negotiation\RequestValidator;
use haddowg\JsonApi\Operation\OperationContext;
use haddowg\JsonApi\Operation\OperationFactory;
use haddowg\JsonApi\Request\JsonApiRequest;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Validation\DocumentValidator;
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
        private readonly ?DocumentValidator $schemaValidator = null,
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

        // A write body must be well-formed JSON before core's hydrator reads it —
        // the belt core's RequestBodyParsingMiddleware would run, called directly.
        // POST/PATCH always carry a body; a resource DELETE does not, but a
        // *relationship* DELETE (remove-from) carries the `{data:[…]}` linkage to
        // remove, so it is validated too.
        $method = $jsonApiRequest->getMethod();
        $carriesBody = \in_array($method, ['POST', 'PATCH'], true)
            || ($method === 'DELETE' && $target->isRelationshipEndpoint);
        if ($carriesBody) {
            $validator->validateJsonBody($jsonApiRequest);

            // The required-top-level-member rule is a resource-document rule: a
            // resource body's `data` must be present (and an object). A
            // relationship-endpoint body's `data` is *linkage* — legitimately
            // `null` (to-one clear) or `[]` (to-many clear), both of which the rule
            // would reject as "missing". The exact linkage shape is instead
            // validated by core's relationship-linkage parser
            // ({@see \haddowg\JsonApi\Request\JsonApiRequest::getRelationshipLinkageToOne()}
            // / `getRelationshipLinkageToMany()`) when the handler reads it, so the
            // rule is skipped for relationship-endpoint writes.
            if ($target->isRelationshipEndpoint === false) {
                $validator->validateTopLevelMembers($jsonApiRequest);
            }

            $this->validateSchema($jsonApiRequest);
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

    /**
     * Runs the optional opis structural linter over the parsed write body, when
     * `json_api.schema_validation` wired one. A schema failure throws core's
     * {@see \haddowg\JsonApi\Exception\RequestBodyInvalidJsonApi} (`400`), which
     * the exception listener renders. This is a belt-and-braces document-shape
     * check, separate from the semantic Symfony Validator bridge (`422`).
     */
    private function validateSchema(JsonApiRequestInterface $request): void
    {
        if ($this->schemaValidator === null) {
            return;
        }

        $document = $request->getParsedBody();
        if ($document !== null) {
            $this->schemaValidator->validateRequest($document);
        }
    }
}
