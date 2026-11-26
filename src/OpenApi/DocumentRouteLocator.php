<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\OpenApi;

use haddowg\JsonApiBundle\Controller\OpenApiController;
use haddowg\JsonApiBundle\Routing\JsonApiRoute;
use haddowg\JsonApiBundle\Server\ServerProvider;
use Symfony\Component\Config\ConfigCacheFactoryInterface;
use Symfony\Component\Config\ConfigCacheInterface;
use Symfony\Component\Routing\Exception\ExceptionInterface as RoutingException;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;

/**
 * Finds the route that serves each server's OpenAPI document when the bundle's named
 * routes aren't registered, because the app routes {@see OpenApiController} itself.
 *
 * A route serves a server's document when its `_controller` is the controller (the class,
 * `Class::__invoke`, `Class:__invoke` or `[Class, '__invoke']`), its `format` default names
 * the format looked for (none means JSON, see {@see DocumentFormat}), and its `server` route
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
 * keeps the result. In debug the stored result isn't used. Instead the scan goes into a
 * config cache file whose metadata is the route collection's resources, the same check the
 * router makes for its matcher, so a request loads the collection and scans again only
 * after a route file or the container changes. Each file name carries a hash of the
 * declared servers and the combined flag. Without a config cache factory, or when the file
 * can't be written, debug scans once per process.
 *
 * @phpstan-type DocumentRoute array{route: string, parameters: array<string, string>}
 */
final class DocumentRouteLocator
{
    /** @var array<string, array<string, DocumentRoute|null>> the scan per format */
    private array $routes = [];

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
        private readonly ?ConfigCacheFactoryInterface $configCacheFactory = null,
        private readonly ?string $cacheDir = null,
    ) {}

    /**
     * The route serving `$server`'s document (or the combined one), or null when none does.
     *
     * @return DocumentRoute|null
     */
    public function find(string $server, DocumentFormat $format = DocumentFormat::Json): ?array
    {
        return $this->routes($format)[$this->combined ? OpenApiController::COMBINED_KEY : $server] ?? null;
    }

    /**
     * Scans the routes afresh and keeps the result for this process, so a document the
     * same warmup builds afterwards links through it rather than an older stored scan.
     *
     * @return array<string, DocumentRoute|null>
     */
    public function rescan(DocumentFormat $format = DocumentFormat::Json): array
    {
        return $this->routes[$format->value] = $this->scan($format);
    }

    /**
     * Scans the route collection for the document route of every mounted server, keyed
     * by server name, or by {@see OpenApiController::COMBINED_KEY} alone in combined mode.
     *
     * @return array<string, DocumentRoute|null>
     */
    private function scan(DocumentFormat $format, ?RouteCollection $routes = null): array
    {
        $collection = ($routes ?? $this->router->getRouteCollection())->all();
        $mounted = self::mountedServers($collection);
        $servers = \array_values(\array_filter($this->servers, static fn(string $server): bool => isset($mounted[$server])));

        if ($this->combined) {
            return $servers === [] ? [] : [OpenApiController::COMBINED_KEY => $this->firstFor($collection, $this->servers[0] ?? ServerProvider::DEFAULT_SERVER, $format)];
        }

        $routes = [];
        foreach ($servers as $server) {
            $routes[$server] = $this->firstFor($collection, $server, $format);
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
    private function routes(DocumentFormat $format): array
    {
        if (isset($this->routes[$format->value])) {
            return $this->routes[$format->value];
        }

        if ($this->debug) {
            return $this->routes[$format->value] = $this->debugScan($format);
        }

        $routes = $this->store->readDocumentRoutes($format);
        if ($routes === null) {
            $routes = $this->scan($format);
            try {
                $this->store->writeDocumentRoutes($routes, $format);
            } catch (\Throwable) {
                // A read-only cache dir only costs this process the scan it just did.
            }
        }

        return $this->routes[$format->value] = $routes;
    }

    /**
     * The scan in debug, read from a config cache file while the route collection's
     * resources are fresh, so a fresh request never loads the collection.
     *
     * @return array<string, DocumentRoute|null>
     */
    private function debugScan(DocumentFormat $format): array
    {
        if ($this->configCacheFactory === null || $this->cacheDir === null) {
            return $this->scan($format);
        }

        $file = \sprintf('%s/document_routes.%s.%s.php', $this->cacheDir, $format->value, \hash('xxh128', \serialize([$this->servers, $this->combined])));
        $cache = $this->configCacheFactory->cache($file, function (ConfigCacheInterface $cache) use ($format): void {
            $collection = $this->router->getRouteCollection();
            $routes = $this->routes[$format->value] = $this->scan($format, $collection);
            try {
                $cache->write('<?php return ' . \var_export($routes, true) . ';' . \PHP_EOL, $collection->getResources());
            } catch (\Throwable) {
                // An unwritable cache dir only costs the scan, as without a cache.
            }
        });

        if (isset($this->routes[$format->value])) {
            return $this->routes[$format->value];
        }

        /** @var array<string, DocumentRoute|null>|null $cached */
        $cached = \is_file($cache->getPath()) ? require $cache->getPath() : null;

        return \is_array($cached) ? $cached : $this->scan($format);
    }

    /**
     * @param array<string, Route> $collection
     *
     * @return DocumentRoute|null
     */
    private function firstFor(array $collection, string $server, DocumentFormat $format): ?array
    {
        $parametric = [];
        foreach ($collection as $name => $route) {
            if (!self::servesDocument($route->getDefault('_controller')) || DocumentFormat::ofRoute($route->getDefault(DocumentFormat::ROUTE_DEFAULT)) !== $format) {
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
