<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\OpenApi;

use haddowg\JsonApiBundle\Controller\OpenApiController;
use haddowg\JsonApiBundle\Routing\JsonApiRoute;
use haddowg\JsonApiBundle\Server\ServerProvider;
use Symfony\Component\Routing\Exception\ExceptionInterface as RoutingException;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouterInterface;

/**
 * Finds the route that serves each server's OpenAPI document when the bundle's named
 * routes aren't registered, because the app routes {@see OpenApiController} itself.
 *
 * A route serves a server's document when its `_controller` is the controller (the class,
 * `Class::__invoke`, `Class:__invoke` or `[Class, '__invoke']`) and its `server` route
 * attribute is that server: a `server` default naming it, no `server` at all for the
 * default server, or a `{server}` path variable whose requirement accepts the name. In
 * combined mode every such route serves the one document. A route with a fixed server
 * wins over a parametric one, then the collection's order decides.
 *
 * Only a server with JSON:API routes mounted is looked up, meaning some route carries the
 * {@see JsonApiRoute::MARKER} default and names the server in `_jsonapi_server`. A server
 * no route serves renders no links, so it has no entry; in combined mode the one entry is
 * there only when at least one server is mounted.
 *
 * The scan reads the whole route collection, so it runs once: the {@see DocumentRouteWarmer}
 * writes its result beside the warmed documents, and a process without one scans once and
 * keeps the result. In debug the result is never read from or written to disk, so a route
 * added between requests is found without clearing the cache.
 *
 * @phpstan-type DocumentRoute array{route: string, parameters: array<string, string>}
 */
final class DocumentRouteLocator
{
    /** @var array<string, DocumentRoute|null>|null */
    private ?array $routes = null;

    /**
     * @param list<string> $servers  the declared server names
     * @param bool         $combined `json_api.openapi.multi_server === combined`
     */
    public function __construct(
        private readonly RouterInterface $router,
        private readonly ArtifactStore $store,
        private readonly array $servers,
        private readonly bool $combined = false,
        private readonly bool $debug = false,
    ) {}

    /**
     * The route serving `$server`'s document (or the combined one), or null when none does.
     *
     * @return DocumentRoute|null
     */
    public function find(string $server): ?array
    {
        return $this->routes()[$this->combined ? OpenApiController::COMBINED_KEY : $server] ?? null;
    }

    /**
     * Scans the routes afresh and keeps the result for this process, so a document the
     * same warmup builds afterwards links through it rather than an older stored scan.
     *
     * @return array<string, DocumentRoute|null>
     */
    public function rescan(): array
    {
        return $this->routes = $this->scan();
    }

    /**
     * Scans the route collection for the document route of every mounted server, keyed
     * by server name, or by {@see OpenApiController::COMBINED_KEY} alone in combined mode.
     *
     * @return array<string, DocumentRoute|null>
     */
    private function scan(): array
    {
        $collection = $this->router->getRouteCollection()->all();
        $mounted = self::mountedServers($collection);
        $servers = \array_values(\array_filter($this->servers, static fn(string $server): bool => isset($mounted[$server])));

        if ($this->combined) {
            return $servers === [] ? [] : [OpenApiController::COMBINED_KEY => $this->firstFor($collection, $this->servers[0] ?? ServerProvider::DEFAULT_SERVER)];
        }

        $routes = [];
        foreach ($servers as $server) {
            $routes[$server] = $this->firstFor($collection, $server);
        }

        return $routes;
    }

    /**
     * The servers some JSON:API route serves, as keys.
     *
     * @param array<string, Route> $collection
     *
     * @return array<string, true>
     */
    private static function mountedServers(array $collection): array
    {
        $mounted = [];
        foreach ($collection as $route) {
            if ($route->getDefault(JsonApiRoute::MARKER) !== true) {
                continue;
            }

            $server = $route->getDefault('_jsonapi_server');
            $mounted[\is_string($server) && $server !== '' ? $server : ServerProvider::DEFAULT_SERVER] = true;
        }

        return $mounted;
    }

    /**
     * @return array<string, DocumentRoute|null>
     */
    private function routes(): array
    {
        if ($this->routes !== null) {
            return $this->routes;
        }

        $routes = $this->debug ? null : $this->store->readDocumentRoutes();
        if ($routes === null) {
            $routes = $this->scan();
            if (!$this->debug) {
                try {
                    $this->store->writeDocumentRoutes($routes);
                } catch (\Throwable) {
                    // A read-only cache dir only costs this process the scan it just did.
                }
            }
        }

        return $this->routes = $routes;
    }

    /**
     * @param array<string, Route> $collection
     *
     * @return DocumentRoute|null
     */
    private function firstFor(array $collection, string $server): ?array
    {
        $parametric = [];
        foreach ($collection as $name => $route) {
            if (!self::servesDocument($route->getDefault('_controller'))) {
                continue;
            }

            if (\in_array('server', $route->compile()->getPathVariables(), true)) {
                $parametric[$name] = $route;

                continue;
            }

            if (!$this->combined && self::fixedServer($route) !== $server) {
                continue;
            }

            if ($this->generates($name, [])) {
                return ['route' => $name, 'parameters' => []];
            }
        }

        $candidates = $this->combined ? $this->servers : [$server];
        foreach ($parametric as $name => $route) {
            foreach ($candidates as $candidate) {
                if (self::accepts($route, $candidate) && $this->generates($name, ['server' => $candidate])) {
                    return ['route' => $name, 'parameters' => ['server' => $candidate]];
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, string> $parameters
     */
    private function generates(string $name, array $parameters): bool
    {
        try {
            $this->router->generate($name, $parameters);

            return true;
        } catch (RoutingException) {
            return false;
        }
    }

    private static function servesDocument(mixed $controller): bool
    {
        if (\is_array($controller) && \count($controller) === 2 && \is_string($controller[0] ?? null) && \is_string($controller[1] ?? null)) {
            $controller = $controller[0] . '::' . $controller[1];
        }

        if (!\is_string($controller)) {
            return false;
        }

        $controller = \ltrim($controller, '\\');
        if (\str_contains($controller, '::')) {
            [$class, $method] = \explode('::', $controller, 2);
        } elseif (\substr_count($controller, ':') === 1) {
            [$class, $method] = \explode(':', $controller, 2);
        } else {
            [$class, $method] = [$controller, '__invoke'];
        }

        return \strcasecmp($class, OpenApiController::class) === 0 && \strcasecmp($method, '__invoke') === 0;
    }

    /**
     * The server a route without a `{server}` variable serves: its `server` default, else
     * the default server, as the controller resolves it.
     */
    private static function fixedServer(Route $route): string
    {
        $server = $route->getDefault('server');

        return \is_string($server) && $server !== '' ? $server : ServerProvider::DEFAULT_SERVER;
    }

    private static function accepts(Route $route, string $server): bool
    {
        $requirement = $route->getRequirement('server') ?? '[^/]++';

        return \preg_match('{^(?:' . $requirement . ')$}D' . ($route->getOption('utf8') ? 'u' : ''), $server) === 1;
    }
}
