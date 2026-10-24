<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\EventListener;

use haddowg\JsonApi\Response\AbstractResponse;
use haddowg\JsonApi\Response\ErrorResponse;
use haddowg\JsonApi\Schema\Link\Link;
use haddowg\JsonApi\Schema\Link\LinkObject;
use haddowg\JsonApi\Server\Server;
use haddowg\JsonApiBundle\OpenApi\DocumentUrlProvider;
use Symfony\Component\HttpKernel\Event\ViewEvent;

/**
 * Points a response's top-level `links.describedby` at the whole OpenAPI document when
 * core can't point it at an operation.
 *
 * Core gives each document a `describedby` naming the operation that produced it, from
 * the resolver the {@see \haddowg\JsonApiBundle\Server\ServerProvider} sets on the
 * request's Server. That covers every CRUD, relationship and action endpoint. It leaves
 * out a response whose operation the document doesn't carry: an Atomic Operations
 * result (core doesn't know that endpoint's path) or a handler response naming an
 * operation nobody serves. For those this listener stamps the document's URL, the same
 * one the operation pointers use, through core's
 * {@see AbstractResponse::withDescribedby()}. It runs on `kernel.view` before the
 * {@see ViewListener}.
 *
 * With no document URL (`json_api.openapi.describedby: false`, generation disabled, or
 * the document routes not registered and no `document_url` configured) it does nothing.
 */
final readonly class DescribedbyListener
{
    public function __construct(private DocumentUrlProvider $urls) {}

    public function onKernelView(ViewEvent $event): void
    {
        $request = $event->getRequest();

        $response = $request->attributes->get(RequestListener::RESPONSE_ATTRIBUTE);
        // An error document describes the failure, not an operation, as core decides
        // for the documents it describes itself.
        if (!$response instanceof AbstractResponse || $response instanceof ErrorResponse) {
            return;
        }

        $server = $request->attributes->get(RequestListener::SERVER_ATTRIBUTE);
        $operation = $response->operationReference();
        if ($server instanceof Server && $operation !== null && $server->describedByResolver()?->describedBy($operation) !== null) {
            return;
        }

        $name = $request->attributes->get('_jsonapi_server');
        $url = $this->urls->forServer(\is_string($name) && $name !== '' ? $name : null);
        if ($url === null) {
            return;
        }

        $request->attributes->set(
            RequestListener::RESPONSE_ATTRIBUTE,
            // In the same form as the server's other links: `{"href": ...}` unless the
            // server opted back out to strings.
            $response->withDescribedby($server instanceof Server && !$server->rendersLinkObjects() ? new Link($url) : new LinkObject($url)),
        );
    }
}
