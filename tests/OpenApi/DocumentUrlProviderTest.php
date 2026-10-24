<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\OpenApi;

use haddowg\JsonApiBundle\OpenApi\DocumentUrlProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

final class DocumentUrlProviderTest extends TestCase
{
    #[Test]
    public function theDefaultIsTheServersDocumentRoutePathIncludingItsPrefix(): void
    {
        $urls = new DocumentUrlProvider($this->generator(withRoutes: true));

        self::assertSame('/api/docs.json', $urls->forServer());
        self::assertSame('/api/admin/docs.json', $urls->forServer('admin'));
    }

    #[Test]
    public function combinedModePointsEveryServerAtTheOneDocument(): void
    {
        $urls = new DocumentUrlProvider($this->generator(withRoutes: true), combined: true);

        self::assertSame('/api/docs.json', $urls->forServer('admin'));
    }

    #[Test]
    public function aConfiguredUrlIsUsedAsGivenWithTheServerNameSubstituted(): void
    {
        $url = 'https://docs.example.test/{server}.json';

        self::assertSame('https://docs.example.test/admin.json', (new DocumentUrlProvider($this->generator(withRoutes: false), configuredUrl: $url))->forServer('admin'));
        self::assertSame('https://docs.example.test/combined.json', (new DocumentUrlProvider($this->generator(withRoutes: false), combined: true, configuredUrl: $url))->forServer('admin'));
    }

    #[Test]
    public function thereIsNoUrlWhenTheDocumentIsNotServedOrDescribedbyIsOff(): void
    {
        self::assertNull((new DocumentUrlProvider($this->generator(withRoutes: false)))->forServer());
        self::assertNull((new DocumentUrlProvider($this->generator(withRoutes: true), enabled: false))->forServer());
        self::assertNull((new DocumentUrlProvider($this->generator(withRoutes: true), enabled: false, configuredUrl: 'https://docs.example.test/openapi.json'))->forServer());
    }

    private function generator(bool $withRoutes): UrlGenerator
    {
        $routes = new RouteCollection();
        if ($withRoutes) {
            $routes->add('jsonapi.openapi.default', new Route('/api/docs.json'));
            $routes->add('jsonapi.openapi.server', new Route('/api/{server}/docs.json'));
        }

        return new UrlGenerator($routes, new RequestContext());
    }
}
