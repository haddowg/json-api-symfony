<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Security;

use haddowg\JsonApi\OpenApi\Metadata\OperationKey;
use haddowg\JsonApi\OpenApi\Metadata\OperationKind;
use haddowg\JsonApiBundle\Operation\TargetResolver;
use haddowg\JsonApiBundle\Routing\JsonApiRouteLoader;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouterInterface;

/**
 * What the Symfony firewall in front of an operation's route can answer before the
 * bundle runs: a `401` from an authenticator rejecting the credentials a request
 * carries, and a `401` or `403` from an `access_control` rule.
 *
 * It finds the route the {@see JsonApiRouteLoader} emitted for the operation, builds a
 * request for its path, method and host, and asks the firewall map and the access map
 * about it, as the firewall does at runtime. A rule matching on something that request
 * cannot carry, such as the client's IP, is judged against a request from
 * `127.0.0.1`. An operation whose route it cannot find is answered as refusable, since
 * nothing can rule a firewall out.
 *
 * @internal
 */
final class RouteFirewall
{
    /**
     * @var array<string, list<Route>>|null routes by server
     */
    private ?array $routes = null;

    /**
     * @var array<string, array{bool, bool}>
     */
    private array $answers = [];

    /**
     * @param object|null $firewallMap the `security.firewall.map` service, read through its `getFirewallConfig()`
     * @param object|null $accessMap   the `security.access_map` service, read through its `getPatterns()`
     */
    public function __construct(
        private readonly ?RouterInterface $router = null,
        private readonly ?object $firewallMap = null,
        private readonly ?object $accessMap = null,
    ) {}

    /**
     * Whether a request for the operation can be answered `401` by an authenticator on
     * its firewall.
     */
    public function authenticates(string $server, ?string $type, OperationKey $operation, ?string $relationSegment = null): bool
    {
        return $this->answer($server, $type, $operation, $relationSegment)[0];
    }

    /**
     * Whether an `access_control` rule requires anything of the caller for the
     * operation, which refuses an anonymous caller with `401` and any other with `403`.
     */
    public function restricts(string $server, ?string $type, OperationKey $operation, ?string $relationSegment = null): bool
    {
        return $this->answer($server, $type, $operation, $relationSegment)[1];
    }

    /**
     * @return array{bool, bool}
     */
    private function answer(string $server, ?string $type, OperationKey $operation, ?string $relationSegment): array
    {
        if ($this->firewallMap === null && $this->accessMap === null) {
            return [false, false];
        }

        $key = $server . "\0" . $type . "\0" . $operation->name() . "\0" . $operation->method . "\0" . $relationSegment;
        if (isset($this->answers[$key])) {
            return $this->answers[$key];
        }

        $request = $this->requestFor($server, $type, $operation, $relationSegment);

        return $this->answers[$key] = $request === null
            ? [true, true]
            : [$this->authenticatorsFor($request), $this->accessRulesFor($request)];
    }

    private function authenticatorsFor(Request $request): bool
    {
        if ($this->firewallMap === null || !\method_exists($this->firewallMap, 'getFirewallConfig')) {
            return false;
        }

        $config = $this->firewallMap->getFirewallConfig($request);
        if (!\is_object($config) || !\method_exists($config, 'isSecurityEnabled') || !\method_exists($config, 'getAuthenticators')) {
            return false;
        }

        return $config->isSecurityEnabled() && $config->getAuthenticators() !== [];
    }

    private function accessRulesFor(Request $request): bool
    {
        if ($this->accessMap === null || !\method_exists($this->accessMap, 'getPatterns')) {
            return false;
        }

        $patterns = $this->accessMap->getPatterns($request);
        $attributes = \is_array($patterns) ? ($patterns[0] ?? null) : null;
        if (!\is_array($attributes)) {
            return false;
        }

        // PUBLIC_ACCESS lets every caller through, so a rule granting only it refuses no one.
        foreach ($attributes as $attribute) {
            if ($attribute !== 'PUBLIC_ACCESS') {
                return true;
            }
        }

        return false;
    }

    private function requestFor(string $server, ?string $type, OperationKey $operation, ?string $relationSegment): ?Request
    {
        $route = $this->routeFor($server, $type, $operation);
        if ($route === null || $this->router === null) {
            return null;
        }

        $path = \strtr($route->getPath(), [
            '{id}' => '1',
            '{relationship}' => $relationSegment ?? 'relationship',
        ]);
        $context = $this->router->getContext();
        $host = $route->getHost() !== '' ? $route->getHost() : $context->getHost();

        return Request::create(
            \sprintf('%s://%s%s%s', $context->getScheme(), $host !== '' ? $host : 'localhost', $context->getBaseUrl(), $path),
            $operation->method,
        );
    }

    private function routeFor(string $server, ?string $type, OperationKey $operation): ?Route
    {
        foreach ($this->routes()[$server] ?? [] as $route) {
            if ($route->getMethods() !== [] && !\in_array($operation->method, $route->getMethods(), true)) {
                continue;
            }
            if (self::serves($route, $type, $operation)) {
                return $route;
            }
        }

        return null;
    }

    private static function serves(Route $route, ?string $type, OperationKey $operation): bool
    {
        if ($operation->kind === OperationKind::Atomic) {
            return $route->getDefault(JsonApiRouteLoader::ATOMIC_ATTRIBUTE) === true;
        }
        if ($route->getDefault(TargetResolver::TYPE_ATTRIBUTE) !== $type) {
            return false;
        }

        $action = $route->getDefault(JsonApiRouteLoader::ACTION_ATTRIBUTE);
        if ($operation->kind === OperationKind::Action) {
            return $action === $operation->actionPath
                && $route->getDefault(JsonApiRouteLoader::ACTION_SCOPE_ATTRIBUTE) === $operation->actionScope?->name;
        }
        if ($action !== null) {
            return false;
        }

        $endpoint = $route->getDefault(TargetResolver::RELATIONSHIP_ENDPOINT_ATTRIBUTE);
        $hasId = \str_contains($route->getPath(), '{id}');

        return match ($operation->kind) {
            OperationKind::FetchCollection, OperationKind::Create => $endpoint === null && !$hasId,
            OperationKind::FetchOne, OperationKind::Update, OperationKind::Delete => $endpoint === null && $hasId,
            OperationKind::FetchRelated => $endpoint === false,
            OperationKind::FetchRelationship, OperationKind::UpdateRelationship, OperationKind::AddRelationship, OperationKind::RemoveRelationship => $endpoint === true,
        };
    }

    /**
     * @return array<string, list<Route>>
     */
    private function routes(): array
    {
        if ($this->routes !== null) {
            return $this->routes;
        }

        $this->routes = [];
        foreach ($this->router?->getRouteCollection() ?? [] as $route) {
            $server = $route->getDefault('_jsonapi_server');
            if (\is_string($server)) {
                $this->routes[$server][] = $route;
            }
        }

        return $this->routes;
    }
}
