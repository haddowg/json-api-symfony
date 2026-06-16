<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Server;

use haddowg\JsonApi\Server\Server;

/**
 * Resolves a configured {@see Server} by its `_jsonapi_server` route-default name.
 *
 * Multi-server support is config-declared (bundle ADR 0034): top-level
 * `base_uri`/`version` define the implicit `default` server, and an optional
 * `json_api.servers` map declares additional named servers. One {@see ServerFactory}
 * is built per declared server, each holding only that server's registered types;
 * this provider holds a name → factory service locator and resolves the requested
 * server by name. An unknown name is a {@see \LogicException} — a wiring fault — not
 * a runtime `404`.
 */
final class ServerProvider
{
    public const string DEFAULT_SERVER = 'default';

    /**
     * @param \Psr\Container\ContainerInterface $factories a service locator keyed by server name, each entry a {@see ServerFactory}
     */
    public function __construct(private readonly \Psr\Container\ContainerInterface $factories) {}

    /**
     * The Server for `$name`, or the `default` server when `$name` is null.
     *
     * @throws \LogicException when an unknown server name is requested
     */
    public function get(?string $name = null): Server
    {
        $name ??= self::DEFAULT_SERVER;

        if (!$this->factories->has($name)) {
            throw new \LogicException(\sprintf('No JSON:API server is configured under the name "%s".', $name));
        }

        $factory = $this->factories->get($name);
        \assert($factory instanceof ServerFactory);

        return $factory->create();
    }
}
