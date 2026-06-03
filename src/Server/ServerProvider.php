<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Server;

use haddowg\JsonApi\Server\Server;

/**
 * Resolves a configured {@see Server} by its `_jsonapi_server` route-default name.
 *
 * Phase 0 is single-server: the only name is the implicit `default`, served by
 * the one {@see ServerFactory}. This indirection is the seam that lets multi-
 * server support (a name → factory map) drop in later without the listeners
 * changing — they always ask the provider for a server by name.
 */
final class ServerProvider
{
    public const string DEFAULT_SERVER = 'default';

    public function __construct(private readonly ServerFactory $defaultServerFactory) {}

    /**
     * The Server for `$name`, or the `default` server when `$name` is null.
     *
     * @throws \LogicException when an unknown server name is requested
     */
    public function get(?string $name = null): Server
    {
        $name ??= self::DEFAULT_SERVER;

        if ($name !== self::DEFAULT_SERVER) {
            throw new \LogicException(\sprintf('No JSON:API server is configured under the name "%s".', $name));
        }

        return $this->defaultServerFactory->create();
    }
}
