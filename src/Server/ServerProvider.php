<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Server;

use haddowg\JsonApi\Server\Server;
use haddowg\JsonApiBundle\OpenApi\DescribedByResolverProvider;
use haddowg\JsonApiBundle\Routing\JsonApiRouteLoader;
use Symfony\Component\HttpFoundation\Request;

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
    public function __construct(
        private readonly \Psr\Container\ContainerInterface $factories,
        private readonly ?DescribedByResolverProvider $describedBy = null,
    ) {}

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

    /**
     * The Server for the request's `_jsonapi_server` route default, with its links
     * based where the request found it and described by the server's OpenAPI document.
     *
     * A configured `base_uri` is returned as it is. An empty one would make core base
     * every link on the request origin alone, dropping the import prefix a server's
     * routes are mounted under; so when the route carries a mount (or the front
     * controller lives below the web root) the Server is given the effective base
     * `origin + base path + mount` for this request instead (bundle ADR 0121).
     *
     * The `describedby` resolver is set here rather than on the memoized Server because
     * the default document URL is a path generated against this request's base path.
     *
     * @throws \LogicException when the route names an unknown server
     */
    public function forRequest(Request $request): Server
    {
        $name = $request->attributes->get('_jsonapi_server');
        $name = \is_string($name) && $name !== '' ? $name : self::DEFAULT_SERVER;
        $server = $this->get($name);

        $describedBy = $this->describedBy?->forServer($name);
        if ($describedBy !== null) {
            $server = $server->withDescribedByResolver($describedBy);
        }

        if ($server->baseUri() !== '') {
            return $server;
        }

        $path = $request->getBaseUrl() . self::mountOf($request);
        if ($path === '') {
            return $server;
        }

        return $server->withBaseUri($request->getSchemeAndHttpHost() . $path);
    }

    /**
     * The mount the matched route recorded. A prefix with placeholders
     * (`/{tenant}/api`) is read back from the request path, one segment per segment
     * of the prefix.
     */
    private static function mountOf(Request $request): string
    {
        $mount = $request->attributes->get(JsonApiRouteLoader::MOUNT_ATTRIBUTE);
        if (!\is_string($mount) || $mount === '') {
            return '';
        }

        if (!\str_contains($mount, '{')) {
            return $mount;
        }

        $segments = \explode('/', $request->getPathInfo());

        return \implode('/', \array_slice($segments, 0, \substr_count($mount, '/') + 1));
    }
}
