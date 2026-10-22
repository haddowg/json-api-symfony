<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\OpenApi\Metadata;

use haddowg\JsonApi\OpenApi\Server;
use haddowg\JsonApi\OpenApi\ServerVariable;
use haddowg\JsonApiBundle\Routing\JsonApiRouteLoader;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;

/**
 * The OpenAPI `servers` for a server with an empty `base_uri`, read from the mounts
 * {@see \haddowg\JsonApiBundle\Routing\MountRecordingLoader} recorded on its routes
 * (bundle ADR 0121).
 *
 * Each distinct mount becomes one relative server URL (`/api/v5`), sorted so the
 * document doesn't depend on route order. A mount with placeholders
 * (`/{tenant}/api`) becomes a templated URL whose variables default to the route
 * defaults; a mount with a placeholder that has no default is left out, because OAS
 * requires a default and any invented value would advertise a URL that may not exist.
 * When no route of the server is mounted, there are no servers, as before.
 *
 * It reads the router's route collection, never a request, so the document is the
 * same whether the cache warmer or the first request builds it. The front
 * controller's base path is only known per request and is not included.
 */
final class MountServers
{
    public function __construct(private readonly ?RouterInterface $router = null) {}

    /**
     * @return list<Server>
     */
    public function forServer(string $serverName): array
    {
        if ($this->router === null) {
            return [];
        }

        return self::fromRoutes($this->router->getRouteCollection(), $serverName);
    }

    /**
     * @return list<Server>
     */
    public static function fromRoutes(RouteCollection $routes, string $serverName): array
    {
        /** @var array<string, array<string, mixed>> $mounts mount => the defaults of its first route */
        $mounts = [];
        foreach ($routes as $route) {
            if ($route->getDefault('_jsonapi_server') !== $serverName
                || !\is_string($route->getDefault(JsonApiRouteLoader::PATH_ATTRIBUTE))) {
                continue;
            }

            $mount = $route->getDefault(JsonApiRouteLoader::MOUNT_ATTRIBUTE);
            $mounts[\is_string($mount) ? $mount : ''] ??= $route->getDefaults();
        }

        if (\array_keys($mounts) === [''] || $mounts === []) {
            return [];
        }

        \ksort($mounts, \SORT_STRING);

        $servers = [];
        foreach ($mounts as $mount => $defaults) {
            $server = self::serverFor((string) $mount, $defaults);
            if ($server !== null) {
                $servers[] = $server;
            }
        }

        return $servers;
    }

    /**
     * @param array<string, mixed> $defaults
     */
    private static function serverFor(string $mount, array $defaults): ?Server
    {
        if ($mount === '') {
            return new Server('/');
        }

        \preg_match_all('/\{!?(\w+)\}/', $mount, $matches);

        $url = $mount;
        $variables = [];
        foreach ($matches[1] as $index => $name) {
            $default = $defaults[$name] ?? null;
            if (!\is_string($default) && !\is_int($default)) {
                return null;
            }

            $url = \str_replace($matches[0][$index], '{' . $name . '}', $url);
            $variables[$name] = new ServerVariable((string) $default);
        }

        return new Server($url, variables: $variables);
    }
}
