<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\EventListener;

use haddowg\JsonApi\Operation\DeleteResourceOperation;
use haddowg\JsonApi\Operation\JsonApiOperationInterface;
use haddowg\JsonApi\Resource\DocumentMeta;
use haddowg\JsonApi\Response\AbstractResponse;
use haddowg\JsonApi\Response\MetaResponse;
use haddowg\JsonApi\Response\RawResponse;
use haddowg\JsonApi\Server\Server;
use haddowg\JsonApiBundle\Operation\DocumentMetaResolver;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
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
    public function __construct(
        private readonly HttpFoundationFactory $httpFoundationFactory,
        // In debug mode a declaring operation's top-level meta is checked against its
        // declaration; production checks nothing.
        private readonly ?DocumentMetaResolver $documentMeta = null,
        private readonly bool $debug = false,
    ) {}

    public function onKernelView(ViewEvent $event): void
    {
        $request = $event->getRequest();

        $response = $request->attributes->get(RequestListener::RESPONSE_ATTRIBUTE);
        if ($response instanceof RawResponse) {
            $event->setResponse(self::raw($response));

            return;
        }

        if (!$response instanceof AbstractResponse) {
            return;
        }

        $server = $request->attributes->get(RequestListener::SERVER_ATTRIBUTE);
        $psrRequest = $request->attributes->get(RequestListener::PSR_REQUEST_ATTRIBUTE);
        if (!$server instanceof Server || !$psrRequest instanceof ServerRequestInterface) {
            return;
        }

        $psrResponse = $response->toPsrResponse($server, $psrRequest);

        $operation = $request->attributes->get(RequestListener::OPERATION_ATTRIBUTE);
        // A delete's declared meta is its meta-only document's, not a resource an After hook rendered.
        if ($this->debug && $this->documentMeta !== null && $operation instanceof JsonApiOperationInterface
            && !($operation instanceof DeleteResourceOperation && !$response instanceof MetaResponse)) {
            $this->checkDocumentMeta($this->documentMeta->forOperation($server, $operation, $psrResponse->getStatusCode()), $psrResponse);
        }
        // Each rendered resource object's meta, and each linkage identifier's, is checked
        // against what its type and relation declare.
        if ($this->debug) {
            self::checkResourceMeta($server, $psrResponse);
        }

        $event->setResponse($this->httpFoundationFactory->createResponse($psrResponse));
    }

    /**
     * Refuses a success document whose top-level meta does not match its declaration.
     *
     * @throws \haddowg\JsonApi\Resource\DocumentMetaMismatch
     */
    private function checkDocumentMeta(DocumentMeta $declared, ResponseInterface $response): void
    {
        if ($declared->isEmpty() || $response->getStatusCode() >= 300) {
            return;
        }

        $body = $response->getBody();
        $content = (string) $body;
        if ($content === '') {
            return;
        }
        $document = \json_decode($content, true, flags: \JSON_THROW_ON_ERROR);
        if ($body->isSeekable()) {
            $body->rewind();
        }
        $meta = \is_array($document) ? ($document['meta'] ?? []) : [];

        $declared->assertSatisfiedBy(\is_array($meta) ? $meta : []);
    }

    /**
     * Refuses a success document whose resource objects' `meta`, or whose linkage
     * identifiers' `meta`, does not match what their types and relations declare.
     *
     * @throws \haddowg\JsonApi\Resource\ResourceMetaMismatch
     */
    private static function checkResourceMeta(Server $server, ResponseInterface $response): void
    {
        if ($response->getStatusCode() >= 300) {
            return;
        }
        $body = $response->getBody();
        $content = (string) $body;
        if ($body->isSeekable()) {
            $body->rewind();
        }
        $document = $content === '' ? null : \json_decode($content, true, flags: \JSON_THROW_ON_ERROR);
        if (!\is_array($document)) {
            return;
        }

        \haddowg\JsonApi\Resource\RenderedMeta::assertConforms(
            $document,
            static fn(string $type): ?object => $server->hasSerializerFor($type) ? $server->serializerFor($type) : null,
        );
    }

    /**
     * A raw action body as a Symfony response: a string as it is, a stream resource or
     * PSR-7 stream streamed out, under its `Content-Type` with `Vary: Accept`, then the
     * headers the action set.
     */
    private static function raw(RawResponse $raw): Response
    {
        $body = $raw->body();
        $headers = ['Content-Type' => $raw->contentType(), 'Vary' => 'Accept', ...$raw->headers()];

        if (\is_string($body)) {
            return new Response($body, $raw->status(), $headers);
        }

        return new StreamedResponse(static function () use ($body): void {
            if ($body instanceof StreamInterface) {
                if ($body->isSeekable()) {
                    $body->rewind();
                }
                while (!$body->eof()) {
                    echo $body->read(8192);
                }

                return;
            }

            \fpassthru($body);
        }, $raw->status(), $headers);
    }
}
