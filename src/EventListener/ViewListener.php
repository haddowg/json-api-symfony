<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\EventListener;

use haddowg\JsonApi\Response\AbstractResponse;
use haddowg\JsonApi\Server\Server;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Component\HttpKernel\Event\ViewEvent;

/**
 * The `kernel.view` listener: it takes the core response value object the
 * {@see RequestListener} stashed (produced by `Server::dispatch()`), renders it to
 * a PSR-7 response via the serializer-free render seam
 * ({@see AbstractResponse::toPsrResponse()} — which builds the JSON:API document
 * array and `json_encode`s it with `JSON_THROW_ON_ERROR` inline), then bridges
 * the PSR-7 message back to an HttpFoundation Response.
 *
 * This is where the spec-compliant body and `Content-Type:
 * application/vnd.api+json` reach HttpFoundation.
 */
final class ViewListener
{
    public function __construct(private readonly HttpFoundationFactory $httpFoundationFactory) {}

    public function onKernelView(ViewEvent $event): void
    {
        $request = $event->getRequest();

        $response = $request->attributes->get(RequestListener::RESPONSE_ATTRIBUTE);
        if (!$response instanceof AbstractResponse) {
            return;
        }

        $server = $request->attributes->get(RequestListener::SERVER_ATTRIBUTE);
        $psrRequest = $request->attributes->get(RequestListener::PSR_REQUEST_ATTRIBUTE);
        if (!$server instanceof Server || !$psrRequest instanceof ServerRequestInterface) {
            return;
        }

        $psrResponse = $response->toPsrResponse($server, $psrRequest);

        $event->setResponse($this->httpFoundationFactory->createResponse($psrResponse));
    }
}
