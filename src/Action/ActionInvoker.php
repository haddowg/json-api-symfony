<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Action;

use haddowg\JsonApi\Exception\ResourceNotFound;
use haddowg\JsonApi\Operation\CustomActionOperation;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Response\AcceptedResponse;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Response\ErrorResponse;
use haddowg\JsonApi\Response\MetaResponse;
use haddowg\JsonApi\Response\NoContentResponse;
use haddowg\JsonApi\Response\SeeOtherResponse;
use haddowg\JsonApi\Server\ResolvingServerInterface;
use haddowg\JsonApi\Server\Server;
use haddowg\JsonApiBundle\DataPersister\DataPersisterRegistry;
use haddowg\JsonApiBundle\DataProvider\DataProviderRegistry;
use haddowg\JsonApiBundle\Event\AfterActionEvent;
use haddowg\JsonApiBundle\Event\BeforeActionEvent;
use haddowg\JsonApiBundle\Server\ServerProvider;
use haddowg\JsonApiBundle\Server\TypeMetadataResolver;
use haddowg\JsonApiBundle\Validation\ResourceValidator;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Invokes a custom, non-CRUD action (bundle ADR 0076, design §5). The single
 * bundle {@see \haddowg\JsonApiBundle\Operation\CrudOperationHandler} delegates its
 * {@see CustomActionOperation} arm here when this optional collaborator is wired
 * (`null` otherwise → the handler renders a `404`).
 *
 * `invoke()` resolves the {@see ActionDescriptor} + {@see ActionHandlerInterface}
 * from the {@see ActionRegistry} by the composite key
 * `(server, type, scope, action)` (a `404` when none); for a resource-scope action
 * it fetches the entity through the type's `DataProvider` (a `404` when absent);
 * for a {@see ActionInput::Document} action it resolves, validates (through the
 * Validator bridge) and hydrates the request document into an `inputType` instance;
 * it then fires the per-action {@see BeforeActionEvent} authz/lifecycle gate, builds
 * the {@see ActionContext}, calls the handler, fires {@see AfterActionEvent}, and
 * returns the handler's response value object.
 *
 * The request-wide serving gate and the strict-query validation are inherited for
 * free from `Server::dispatch()` (the operation is a first-class
 * {@see \haddowg\JsonApi\Operation\JsonApiOperationInterface}), so this invoker
 * owns only the per-action concerns.
 */
final readonly class ActionInvoker
{
    public function __construct(
        private ActionRegistry $registry,
        private DataProviderRegistry $providers,
        private DataPersisterRegistry $persisters,
        private TypeMetadataResolver $types,
        private ?ResourceValidator $validator = null,
        private ?EventDispatcherInterface $dispatcher = null,
    ) {}

    public function invoke(CustomActionOperation $operation): DataResponse|MetaResponse|NoContentResponse|AcceptedResponse|SeeOtherResponse|ErrorResponse
    {
        $server = $operation->context()->server;
        $target = $operation->target();
        $type = $target->type;
        $scope = $target->hasId() ? ActionScope::Resource : ActionScope::Collection;

        $serverName = $this->serverName($operation);

        $descriptor = $this->registry->descriptorFor($serverName, $type, $scope, $operation->action());
        if ($descriptor === null) {
            return ErrorResponse::fromException(new ResourceNotFound());
        }

        $handler = $this->registry->handlerFor($descriptor);

        // Resource scope: resolve the {id} to an entity before the handler runs; a
        // missing entity is a 404, exactly as the CRUD read/update/delete arms.
        $entity = null;
        if ($scope === ActionScope::Resource) {
            $id = $target->id;
            $entity = $id !== null ? $this->providers->forType($type)->fetchOne($type, $id) : null;
            if ($entity === null) {
                return ErrorResponse::fromException(new ResourceNotFound());
            }
        }

        // Document input: validate then hydrate the request document into a fresh
        // inputType instance (None/Raw carry no document, so input stays null).
        $input = null;
        if ($descriptor->input === ActionInput::Document) {
            $body = $operation->body();
            if ($body === null) {
                return ErrorResponse::fromException(new ResourceNotFound());
            }

            $input = $this->resolveAndHydrateInput($server, $descriptor, $handler, $body);
        }

        // Per-action before-gate: the security expression is evaluated against the
        // subject (the entity for resource scope, null for collection scope) by the
        // security subscriber, which throws to deny with a 403; a lifecycle consumer
        // may also abort here.
        $this->dispatch(new BeforeActionEvent($type, $descriptor->path, $entity, $descriptor->security));

        $context = new ActionContext(
            $server,
            $descriptor,
            $entity,
            $input,
            $this->request($operation),
            $operation->queryParameters(),
        );

        $response = $handler->handle($context);

        $this->dispatch(new AfterActionEvent($type, $descriptor->path, $entity));

        return $response;
    }

    /**
     * Resolves the blank input instance, runs the Validator bridge against the
     * `inputType`, then hydrates the request body onto it — mirroring the
     * CrudOperationHandler's validate-then-hydrate create idiom. The blank instance
     * is supplied by the handler when it implements
     * {@see ActionInputFactoryInterface} (a bespoke command DTO with no persister),
     * else by the `inputType` persister's `instantiate()`.
     */
    private function resolveAndHydrateInput(
        ResolvingServerInterface $server,
        ActionDescriptor $descriptor,
        ActionHandlerInterface $handler,
        JsonApiRequestInterface $body,
    ): object {
        $inputType = $descriptor->inputType;

        $instance = $handler instanceof ActionInputFactoryInterface
            ? $handler->newInput($body)
            : $this->persisters->forType($inputType)->instantiate($inputType);

        $this->validate($server, $inputType, $body);

        $hydrated = $server->hydratorFor($inputType)->hydrate($body, $instance);
        \assert(\is_object($hydrated));

        return $hydrated;
    }

    /**
     * Runs the Symfony Validator bridge over the action's request document against
     * the `inputType`'s constraints (the create context — a fresh input instance is
     * being built), when one is wired (it is optional) and the `inputType` has a
     * resource declaring constraints. A bare serializer/hydrator pair declares none,
     * so there is nothing to validate — exactly the CrudOperationHandler idiom.
     */
    private function validate(ResolvingServerInterface $server, string $inputType, JsonApiRequestInterface $body): void
    {
        if ($this->validator === null || !$server instanceof Server) {
            return;
        }

        $resource = $this->types->resourceFor($server, $inputType);
        if ($resource === null) {
            return;
        }

        $this->validator->validate($resource, $body, creating: true);
    }

    /**
     * The current JSON:API request: the action's parsed body when present (Document
     * mode), else the originating PSR-7 request off the operation context (always a
     * {@see JsonApiRequestInterface} on the bundle's request path).
     */
    private function request(CustomActionOperation $operation): JsonApiRequestInterface
    {
        $body = $operation->body();
        if ($body !== null) {
            return $body;
        }

        $request = $operation->context()->httpRequest();
        \assert($request instanceof JsonApiRequestInterface);

        return $request;
    }

    /**
     * The name of the server the action dispatched on, read from the
     * `_jsonapi_server` request attribute the {@see \haddowg\JsonApiBundle\EventListener\RequestListener}
     * carries through, defaulting to the implicit `default` server — the same
     * mechanism the CrudOperationHandler resolves the server name with, so the
     * registry's composite key matches the route loader's per-server descriptors.
     */
    private function serverName(CustomActionOperation $operation): string
    {
        $request = $operation->body() ?? $operation->context()->httpRequest();
        $name = $request?->getAttribute('_jsonapi_server');

        return \is_string($name) && $name !== '' ? $name : ServerProvider::DEFAULT_SERVER;
    }

    private function dispatch(object $event): void
    {
        $this->dispatcher?->dispatch($event);
    }
}
