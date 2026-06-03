<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Server;

use haddowg\JsonApi\Operation\OperationHandlerInterface;
use haddowg\JsonApi\Server\Server;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Builds the immutable core {@see Server} for the implicit `default` server from:
 *  - the Resource service class-strings discovered via the
 *    `haddowg.json_api.resource` tag (held by the {@see ResourceLocator}),
 *    registered through core's lazy container resolver so a Resource can have
 *    real constructor dependencies;
 *  - the configured base URI and JSON:API version;
 *  - the PSR-17 response / stream factories.
 *
 * It deliberately does **not** install core's PSR-15 `Middleware\*` chain — the
 * bundle drives the request lifecycle from kernel listeners that call
 * {@see Server::dispatch()}. It does call `withHandler()` with the bundle's own
 * read handler so `dispatch()` has a target (core's `dispatch()` throws without
 * one).
 *
 * The built Server is an immutable value, so it is memoized and shared.
 */
final class ServerFactory
{
    private ?Server $server = null;

    public function __construct(
        private readonly ResourceLocator $resources,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly string $baseUri,
        private readonly string $version,
        private readonly OperationHandlerInterface $handler,
    ) {}

    /**
     * The configured, memoized Server for the `default` API surface.
     */
    public function create(): Server
    {
        if ($this->server !== null) {
            return $this->server;
        }

        $server = Server::make()
            ->withBaseUri($this->baseUri)
            ->withVersion($this->version)
            ->withPsr17($this->responseFactory, $this->streamFactory)
            ->withContainer($this->resources);

        foreach ($this->resources->classes() as $resourceClass) {
            $server = $server->register($resourceClass);
        }

        return $this->server = $server->withHandler($this->handler);
    }
}
