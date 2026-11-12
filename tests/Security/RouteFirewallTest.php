<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Security;

use haddowg\JsonApi\OpenApi\Metadata\OperationKey;
use haddowg\JsonApi\OpenApi\Metadata\OperationKind;
use haddowg\JsonApiBundle\Security\RouteFirewall;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security\FirewallConfig;
use Symfony\Bundle\SecurityBundle\Security\FirewallContext;
use Symfony\Bundle\SecurityBundle\Security\FirewallMap;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpFoundation\ChainRequestMatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestMatcher\IpsRequestMatcher;
use Symfony\Component\HttpFoundation\RequestMatcher\PathRequestMatcher;
use Symfony\Component\HttpFoundation\RequestMatcherInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Http\AccessMap;

/**
 * Which firewalls and `access_control` rules {@see RouteFirewall} judges against a request
 * built from an operation's route, and which it counts as matching because a real request
 * may match them.
 */
final class RouteFirewallTest extends TestCase
{
    #[Test]
    public function aPathRuleIsJudgedAgainstTheRoute(): void
    {
        $access = new AccessMap();
        $access->add(new ChainRequestMatcher([new PathRequestMatcher('^/admin')]), ['ROLE_ADMIN']);
        $firewall = new RouteFirewall(self::router(), accessMap: $access);

        self::assertFalse($firewall->restricts('default', 'articles', OperationKey::crud(OperationKind::FetchOne)));

        $access->add(new ChainRequestMatcher([new PathRequestMatcher('^/articles')]), ['ROLE_USER']);
        $firewall = new RouteFirewall(self::router(), accessMap: $access);

        self::assertTrue($firewall->restricts('default', 'articles', OperationKey::crud(OperationKind::FetchOne)));
    }

    #[Test]
    public function aRuleOnTheClientsIpCountsAsMatching(): void
    {
        $access = new AccessMap();
        $access->add(new ChainRequestMatcher([new PathRequestMatcher('^/articles'), new IpsRequestMatcher('10.0.0.1')]), ['ROLE_ADMIN']);

        self::assertTrue((new RouteFirewall(self::router(), accessMap: $access))->restricts('default', 'articles', OperationKey::crud(OperationKind::FetchOne)));
    }

    #[Test]
    public function aPublicRuleRestrictsNoOne(): void
    {
        $access = new AccessMap();
        $access->add(new ChainRequestMatcher([new PathRequestMatcher('^/articles')]), ['PUBLIC_ACCESS']);
        $access->add(new ChainRequestMatcher([new PathRequestMatcher('^/')]), ['ROLE_USER']);

        self::assertFalse((new RouteFirewall(self::router(), accessMap: $access))->restricts('default', 'articles', OperationKey::crud(OperationKind::FetchOne)));
    }

    #[Test]
    public function theFirstExactFirewallMatchingTheRouteDecides(): void
    {
        $map = self::firewallMap([
            'api' => [new ChainRequestMatcher([new PathRequestMatcher('^/articles')]), []],
            'main' => [null, ['access_token']],
        ]);

        self::assertFalse((new RouteFirewall(self::router(), $map))->authenticates('default', 'articles', OperationKey::crud(OperationKind::FetchOne)));
    }

    #[Test]
    public function anAuthenticatingFirewallBehindACustomMatcherCountsAsMatching(): void
    {
        $custom = new class implements RequestMatcherInterface {
            public function matches(Request $request): bool
            {
                return false;
            }
        };
        $map = self::firewallMap([
            'partner' => [$custom, ['access_token']],
            'api' => [new ChainRequestMatcher([new PathRequestMatcher('^/articles')]), []],
        ]);

        self::assertTrue((new RouteFirewall(self::router(), $map))->authenticates('default', 'articles', OperationKey::crud(OperationKind::FetchOne)));
    }

    #[Test]
    public function anOperationWithNoRouteCountsAsRefusable(): void
    {
        $access = new AccessMap();

        self::assertTrue((new RouteFirewall(self::router(), accessMap: $access))->restricts('default', 'comments', OperationKey::crud(OperationKind::FetchOne)));
    }

    /**
     * @param array<string, array{RequestMatcherInterface|null, list<string>}> $firewalls
     */
    private static function firewallMap(array $firewalls): FirewallMap
    {
        $contexts = [];
        $matchers = [];
        foreach ($firewalls as $name => [$matcher, $authenticators]) {
            $id = 'security.firewall.map.context.' . $name;
            $contexts[$id] = static fn(): FirewallContext => new FirewallContext([], config: new FirewallConfig($name, 'security.user_checker', authenticators: $authenticators));
            $matchers[$id] = $matcher;
        }

        return new FirewallMap(new ServiceLocator($contexts), $matchers);
    }

    private static function router(): RouterInterface
    {
        $routes = new RouteCollection();
        $routes->add('jsonapi.articles.show', new Route('/articles/{id}', ['_jsonapi_server' => 'default', '_jsonapi_type' => 'articles'], methods: ['GET']));

        return new class ($routes) implements RouterInterface {
            private RequestContext $context;

            public function __construct(private readonly RouteCollection $routes)
            {
                $this->context = new RequestContext();
            }

            public function setContext(RequestContext $context): void
            {
                $this->context = $context;
            }

            public function getContext(): RequestContext
            {
                return $this->context;
            }

            public function getRouteCollection(): RouteCollection
            {
                return $this->routes;
            }

            /**
             * @param array<string, mixed> $parameters
             */
            public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
            {
                return '';
            }

            /**
             * @return array<string, mixed>
             */
            public function match(string $pathinfo): array
            {
                return [];
            }
        };
    }
}
