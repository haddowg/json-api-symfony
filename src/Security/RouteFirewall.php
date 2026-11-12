<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Security;

use haddowg\JsonApi\OpenApi\Metadata\OperationKey;
use haddowg\JsonApi\OpenApi\Metadata\OperationKind;
use haddowg\JsonApiBundle\Operation\TargetResolver;
use haddowg\JsonApiBundle\Routing\JsonApiRouteLoader;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\ChainRequestMatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestMatcher\HostRequestMatcher;
use Symfony\Component\HttpFoundation\RequestMatcher\MethodRequestMatcher;
use Symfony\Component\HttpFoundation\RequestMatcher\PathRequestMatcher;
use Symfony\Component\HttpFoundation\RequestMatcherInterface;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouterInterface;

/**
 * What the Symfony firewall in front of an operation's route can answer before the
 * bundle runs: a `401` from an authenticator rejecting the credentials a request
 * carries, and a `401` or `403` from an `access_control` rule.
 *
 * It finds the route the {@see JsonApiRouteLoader} emitted for the operation, builds a
 * request for its path, method and host, and walks the firewalls and `access_control`
 * rules in the order Symfony does. A matcher reading only the path, method or host is
 * judged against that request. One reading anything else, such as the client's IP, its
 * port or a matcher of the application's own, counts as matching, because a real
 * request may match it. So does an operation whose route it cannot find, or a firewall
 * or access map it cannot read.
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
     * @param object|null $firewallMap the `security.firewall.map` service, whose matchers and firewall configs it reads
     * @param object|null $accessMap   the `security.access_map` service, whose rules it reads
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

    /**
     * Walks the firewalls in order, as the firewall map does. A firewall whose matcher
     * reads only the path, method or host is judged against the request, and the first
     * that matches decides. One whose matcher reads anything else, such as the client's
     * IP or a custom matcher, may match a real request, so its authenticators count
     * whether or not it matches this one.
     */
    private function authenticatorsFor(Request $request): bool
    {
        $firewalls = $this->firewalls();
        if ($firewalls === null) {
            return true;
        }

        $maybe = false;
        foreach ($firewalls as [$matcher, $authenticates]) {
            $exact = $matcher === null || self::isExact($matcher);
            if (!$exact) {
                $maybe = $maybe || $authenticates;

                continue;
            }
            if ($matcher === null || $matcher->matches($request)) {
                return $maybe || $authenticates;
            }
        }

        return $maybe;
    }

    /**
     * Walks the `access_control` rules in order, as the access map does, judging a rule
     * by the same test as {@see authenticatorsFor()}.
     */
    private function accessRulesFor(Request $request): bool
    {
        $rules = $this->accessRules();
        if ($rules === null) {
            return true;
        }

        $maybe = false;
        foreach ($rules as [$matcher, $restricts]) {
            if (!self::isExact($matcher)) {
                $maybe = $maybe || $restricts;

                continue;
            }
            if ($matcher->matches($request)) {
                return $maybe || $restricts;
            }
        }

        return $maybe;
    }

    /**
     * The firewall map's matchers in order, each with whether its firewall
     * authenticates; null when the map can't be read.
     *
     * @return list<array{RequestMatcherInterface|null, bool}>|null
     */
    private function firewalls(): ?array
    {
        if ($this->firewallMap === null) {
            return [];
        }

        $map = self::property($this->firewallMap, 'map');
        $container = self::property($this->firewallMap, 'container');
        if (!\is_iterable($map) || !$container instanceof ContainerInterface) {
            return null;
        }

        $firewalls = [];
        foreach ($map as $contextId => $matcher) {
            if (($matcher !== null && !$matcher instanceof RequestMatcherInterface) || !\is_string($contextId)) {
                return null;
            }

            $context = $container->get($contextId);
            $config = \is_object($context) && \method_exists($context, 'getConfig') ? $context->getConfig() : null;
            if (!\is_object($config) || !\method_exists($config, 'isSecurityEnabled') || !\method_exists($config, 'getAuthenticators')) {
                return null;
            }

            $firewalls[] = [$matcher, $config->isSecurityEnabled() && $config->getAuthenticators() !== []];
        }

        return $firewalls;
    }

    /**
     * The `access_control` rules in order, each with whether it requires anything of the
     * caller; null when the access map can't be read.
     *
     * @return list<array{RequestMatcherInterface, bool}>|null
     */
    private function accessRules(): ?array
    {
        if ($this->accessMap === null) {
            return [];
        }

        $map = self::property($this->accessMap, 'map');
        if (!\is_array($map)) {
            return null;
        }

        $rules = [];
        foreach ($map as $rule) {
            $matcher = \is_array($rule) ? ($rule[0] ?? null) : null;
            $attributes = \is_array($rule) ? ($rule[1] ?? null) : null;
            if (!$matcher instanceof RequestMatcherInterface || !\is_array($attributes)) {
                return null;
            }

            // PUBLIC_ACCESS lets every caller through, so a rule granting only it refuses no one.
            $restricts = false;
            foreach ($attributes as $attribute) {
                $restricts = $restricts || $attribute !== 'PUBLIC_ACCESS';
            }
            $rules[] = [$matcher, $restricts];
        }

        return $rules;
    }

    /**
     * Whether a matcher reads only what a request built from the route carries: its path,
     * method and host.
     */
    private static function isExact(RequestMatcherInterface $matcher): bool
    {
        if ($matcher instanceof ChainRequestMatcher) {
            $matchers = self::property($matcher, 'matchers');
            if (!\is_iterable($matchers)) {
                return false;
            }
            foreach ($matchers as $inner) {
                if (!$inner instanceof RequestMatcherInterface || !self::isExact($inner)) {
                    return false;
                }
            }

            return true;
        }

        return $matcher instanceof PathRequestMatcher
            || $matcher instanceof MethodRequestMatcher
            || $matcher instanceof HostRequestMatcher;
    }

    /**
     * A private member of a Symfony service, or null when it has no such member.
     */
    private static function property(object $object, string $name): mixed
    {
        $class = new \ReflectionClass($object);
        while (!$class->hasProperty($name)) {
            $class = $class->getParentClass();
            if ($class === false) {
                return null;
            }
        }

        return $class->getProperty($name)->getValue($object);
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
