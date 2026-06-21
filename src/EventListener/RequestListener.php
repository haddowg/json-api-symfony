<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\EventListener;

use haddowg\JsonApi\Atomic\AtomicExtension;
use haddowg\JsonApi\Atomic\AtomicOperationsParser;
use haddowg\JsonApi\Negotiation\RequestValidator;
use haddowg\JsonApi\Operation\AtomicOperationsOperation;
use haddowg\JsonApi\Operation\CustomActionOperation;
use haddowg\JsonApi\Operation\OperationContext;
use haddowg\JsonApi\Operation\OperationFactory;
use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApi\Operation\Target;
use haddowg\JsonApi\Request\JsonApiRequest;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Server\Server;
use haddowg\JsonApi\Validation\DocumentValidator;
use haddowg\JsonApiBundle\Action\ActionInput;
use haddowg\JsonApiBundle\Action\ActionRegistry;
use haddowg\JsonApiBundle\Action\ActionScope;
use haddowg\JsonApiBundle\Operation\TargetResolver;
use haddowg\JsonApiBundle\Routing\JsonApiRouteLoader;
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
        private readonly ?ActionRegistry $actions = null,
    ) {}

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        // An Atomic Operations batch route (`POST /operations`) carries no
        // `_jsonapi_type` — the batch has no single primary resource — so the target
        // resolver returns null for it; the dedicated `_jsonapi_atomic` marker
        // branches it into the batch path BEFORE the CRUD/action branches.
        if ($request->attributes->get(JsonApiRouteLoader::ATOMIC_ATTRIBUTE) === true) {
            $this->handleAtomic($request);

            return;
        }

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

        // A custom action route carries `_jsonapi_action`; it parses its body per the
        // action's declared input mode (None/Document/Raw) and builds a
        // CustomActionOperation rather than the CRUD operation (bundle ADR 0076).
        $actionName = $request->attributes->get(JsonApiRouteLoader::ACTION_ATTRIBUTE);
        if (\is_string($actionName) && $actionName !== '') {
            $operation = $this->actionOperation($request, $jsonApiRequest, $target, $server, $actionName);
        } else {
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
                // ({@see \haddowg\JsonApi\Request\JsonApiRequest::getRelationshipDataToOne()}
                // / `getRelationshipDataToMany()`) when the handler reads it, so the
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
        }

        $response = $server->dispatch($operation);

        $request->attributes->set(self::RESPONSE_ATTRIBUTE, $response);
        $request->attributes->set(self::SERVER_ATTRIBUTE, $server);
        $request->attributes->set(self::PSR_REQUEST_ATTRIBUTE, $jsonApiRequest);
    }

    /**
     * Runs an Atomic Operations batch (`POST /operations`, bundle ADR 0087): it
     *  1. picks the server by the route's `_jsonapi_server` default;
     *  2. converts the Symfony request to PSR-7 and wraps it in core's
     *     {@see JsonApiRequest};
     *  3. negotiates the **atomic extension** — a {@see RequestValidator} configured
     *     with {@see AtomicExtension::URI} as the one supported extension, then a
     *     REQUIRE check that the `ext` media-type parameter is present on BOTH
     *     `Content-Type` (else `415`) and `Accept` (else `406`), per the extension's
     *     base-spec media-type rules;
     *  4. validates the JSON body is well-formed;
     *  5. parses it via core's {@see AtomicOperationsParser} into
     *     {@see \haddowg\JsonApi\Atomic\OperationDescriptor}s (structural failures → a
     *     `400` {@see \haddowg\JsonApi\Exception\AtomicOperationsInvalid});
     *  6. builds the {@see AtomicOperationsOperation} and dispatches it through
     *     `Server::dispatch()` — which fires serving once for the whole batch and runs
     *     the single handler's atomic arm (the {@see \haddowg\JsonApiBundle\Atomic\AtomicLoopBackend});
     *  7. stashes the response for the {@see ViewListener}.
     *
     * The exception listener renders any thrown error; errors produced under the
     * negotiated extension advertise it on their `Content-Type` (the
     * {@see \haddowg\JsonApi\Atomic\AtomicLoop} does this for the rolled-back error
     * document via {@see \haddowg\JsonApi\Response\AbstractResponse::withExtensions()}).
     */
    private function handleAtomic(\Symfony\Component\HttpFoundation\Request $request): void
    {
        $serverName = $request->attributes->get('_jsonapi_server');
        $server = $this->servers->get(\is_string($serverName) ? $serverName : null);

        $psrRequest = $this->psrHttpFactory->createRequest($request);
        $jsonApiRequest = $psrRequest instanceof JsonApiRequestInterface
            ? $psrRequest
            : new JsonApiRequest($psrRequest);

        // Negotiate with the atomic extension as the sole supported ext: a non-JSON:API
        // media type still 415/406s via the base rules, and a present-but-unsupported
        // ext is rejected. Then REQUIRE the atomic ext on both headers — the extension's
        // media-type contract (an atomic request MUST carry ext on Content-Type AND Accept).
        $validator = new RequestValidator(AtomicExtension::URI);
        $validator->negotiate($jsonApiRequest);
        $this->requireAtomicExtension($jsonApiRequest);

        $validator->validateQueryParams($jsonApiRequest);
        $validator->validateJsonBody($jsonApiRequest);

        $descriptors = (new AtomicOperationsParser())->parse($jsonApiRequest->getParsedBody());

        $operation = new AtomicOperationsOperation(
            $descriptors,
            QueryParameters::fromRequest($jsonApiRequest),
            new OperationContext($server, $jsonApiRequest),
        );

        $response = $server->dispatch($operation);

        $request->attributes->set(self::RESPONSE_ATTRIBUTE, $response);
        $request->attributes->set(self::SERVER_ATTRIBUTE, $server);
        $request->attributes->set(self::PSR_REQUEST_ATTRIBUTE, $jsonApiRequest);
    }

    /**
     * Enforces the extension's media-type contract: the atomic `ext` parameter MUST
     * be present on both the request `Content-Type` (else `415`) and the `Accept`
     * (else `406`). The base {@see RequestValidator::negotiate()} only rejects an
     * *unsupported* ext; it does not require the atomic ext to be present, so a plain
     * `application/vnd.api+json` request to `/operations` would otherwise be accepted.
     *
     * @throws \haddowg\JsonApi\Exception\MediaTypeUnsupported  when Content-Type omits the atomic ext
     * @throws \haddowg\JsonApi\Exception\MediaTypeUnacceptable when Accept omits the atomic ext
     */
    private function requireAtomicExtension(JsonApiRequestInterface $request): void
    {
        if (!\in_array(AtomicExtension::URI, $request->getAppliedExtensions(), true)) {
            throw new \haddowg\JsonApi\Exception\MediaTypeUnsupported($request->getHeaderLine('content-type'));
        }

        if (!\in_array(AtomicExtension::URI, $request->getRequestedExtensions(), true)) {
            throw new \haddowg\JsonApi\Exception\MediaTypeUnacceptable($request->getHeaderLine('accept'));
        }
    }

    /**
     * Builds the {@see CustomActionOperation} for a custom-action route (bundle ADR
     * 0076, design §3/§5/§8). It resolves the action's input mode from the
     * {@see ActionRegistry} descriptor (keyed by the route's server, the target type,
     * the route's scope and the action name) and handles the request body per mode:
     *  - {@see ActionInput::None}: negotiates response `Accept` only — no body is read
     *    and the request `Content-Type` is not required;
     *  - {@see ActionInput::Document}: negotiates, then validates the body as a
     *    JSON:API document exactly as the CRUD write path (well-formed JSON, the
     *    top-level-member rule, the optional opis schema linter) so the operation
     *    carries a parsed body the invoker hydrates + Validator-bridge-validates;
     *  - {@see ActionInput::Raw}: relaxes the request `Content-Type` assertion
     *    ({@see RequestValidator::negotiate()} `requireJsonApiContentType: false`) so a
     *    non-JSON:API upload negotiates, while keeping response `Accept` negotiation
     *    intact; no JSON body / top-level-member validation runs.
     *
     * An unknown action (no descriptor) carries no body and defers the `404` to the
     * invoker (which renders it), so the request still dispatches uniformly.
     */
    private function actionOperation(
        \Symfony\Component\HttpFoundation\Request $request,
        JsonApiRequestInterface $jsonApiRequest,
        Target $target,
        Server $server,
        string $actionName,
    ): CustomActionOperation {
        $scope = $this->actionScope($request);
        $input = $this->actionInput($request, $target->type, $scope, $actionName);

        $validator = new RequestValidator();
        $validator->negotiate($jsonApiRequest, requireJsonApiContentType: $input !== ActionInput::Raw);
        $validator->validateQueryParams($jsonApiRequest);

        // Only a Document-input action reads + validates a JSON:API body; None/Raw
        // carry no document, so the operation body stays null.
        $body = null;
        if ($input === ActionInput::Document) {
            $validator->validateJsonBody($jsonApiRequest);
            $validator->validateTopLevelMembers($jsonApiRequest);
            $this->validateSchema($jsonApiRequest);
            $body = $jsonApiRequest;
        }

        return new CustomActionOperation(
            $target,
            QueryParameters::fromRequest($jsonApiRequest),
            new OperationContext($server, $jsonApiRequest),
            $actionName,
            $jsonApiRequest->getMethod(),
            $body,
        );
    }

    /**
     * The {@see ActionScope} the matched route declares (the `_jsonapi_action_scope`
     * default the route loader stamps), defaulting to {@see ActionScope::Resource}
     * for a route without an explicit scope.
     */
    private function actionScope(\Symfony\Component\HttpFoundation\Request $request): ActionScope
    {
        $scope = $request->attributes->get(JsonApiRouteLoader::ACTION_SCOPE_ATTRIBUTE);

        return \is_string($scope) && $scope === ActionScope::Collection->name
            ? ActionScope::Collection
            : ActionScope::Resource;
    }

    /**
     * The declared input mode for the addressed action, read from its
     * {@see ActionRegistry} descriptor; {@see ActionInput::None} when the registry is
     * absent or no action matches (the invoker renders the `404`, so the body is not
     * read). The descriptor lookup uses the route's `_jsonapi_server` name — the same
     * composite key the route loader keyed the per-server descriptors with, and the
     * one the {@see \haddowg\JsonApiBundle\Action\ActionInvoker} resolves at dispatch.
     */
    private function actionInput(\Symfony\Component\HttpFoundation\Request $request, string $type, ActionScope $scope, string $action): ActionInput
    {
        if ($this->actions === null) {
            return ActionInput::None;
        }

        $serverName = $request->attributes->get('_jsonapi_server');
        $serverName = \is_string($serverName) && $serverName !== '' ? $serverName : ServerProvider::DEFAULT_SERVER;

        $descriptor = $this->actions->descriptorFor($serverName, $type, $scope, $action);
        if ($descriptor === null) {
            return ActionInput::None;
        }

        return $descriptor->input;
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
