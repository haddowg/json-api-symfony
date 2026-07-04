<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

use haddowg\JsonApi\Operation\FetchResourceOperation;
use haddowg\JsonApi\Operation\JsonApiOperationInterface;
use haddowg\JsonApi\Operation\OperationHandlerInterface;
use haddowg\JsonApi\Response\AcceptedResponse;
use haddowg\JsonApi\Response\AtomicResultsResponse;
use haddowg\JsonApi\Response\DataResponse;
use haddowg\JsonApi\Response\ErrorResponse;
use haddowg\JsonApi\Response\IdentifierResponse;
use haddowg\JsonApi\Response\MetaResponse;
use haddowg\JsonApi\Response\NoContentResponse;
use haddowg\JsonApi\Response\RelatedResponse;
use haddowg\JsonApi\Response\SeeOtherResponse;
use haddowg\JsonApiBundle\Operation\CrudOperationHandler;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;

/**
 * The handler-override witness (ADR 0028): a Symfony decorator of the generic
 * {@see CrudOperationHandler}. The generic engine is wired into the `ServerFactory`
 * by service id (`service(CrudOperationHandler::class)`), so decorating that id is
 * transparently picked up — the factory resolves this decorator, which wraps the
 * generic engine as its `$inner`.
 *
 * It intercepts exactly one operation — a {@see FetchResourceOperation} with a
 * non-null target id (a single-resource `GET /{type}/{id}` fetch) — by delegating
 * to the inner engine for the real document and then enriching it with a
 * `meta.decorated => true` marker, the cleanest deterministic witness the
 * response API offers ({@see DataResponse::withMeta()} on the value object the
 * engine already produced). Every other operation — a collection fetch, any
 * write, any relationship operation — is delegated unchanged, so the rest of the
 * generic engine still runs through the decorator.
 *
 * The `#[AsDecorator(CrudOperationHandler::class)]` attribute makes the decoration
 * declarative: the autoconfiguring kernel registers it without explicit
 * `->decorate(...)` wiring.
 */
#[AsDecorator(CrudOperationHandler::class)]
final class InterceptingHandler implements OperationHandlerInterface
{
    public function __construct(
        private readonly OperationHandlerInterface $inner,
    ) {}

    public function handle(JsonApiOperationInterface $operation): DataResponse|MetaResponse|RelatedResponse|IdentifierResponse|NoContentResponse|AtomicResultsResponse|AcceptedResponse|SeeOtherResponse|ErrorResponse
    {
        $response = $this->inner->handle($operation);

        // Intercept only the single-resource fetch (a non-null target id); enrich
        // the engine's own response with a distinguishing marker. Everything else
        // is returned exactly as the generic engine produced it.
        if ($operation instanceof FetchResourceOperation
            && $operation->target()->hasId()
            && $response instanceof DataResponse
        ) {
            return $response->withMeta(['decorated' => true]);
        }

        return $response;
    }
}
