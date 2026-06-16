<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Routing\RouterInterface;

/**
 * The capability-composition witness (backs `capability-composition.md`): the
 * standalone `charts` type — a hand-written
 * {@see \haddowg\JsonApiBundle\Examples\MusicCatalog\Serializer\ChartSerializer}
 * registered by `#[AsJsonApiSerializer]` with **no Resource, no entity, no
 * hydrator** — and the **default-operations asymmetry** it makes observable.
 *
 * A JSON:API type is assembled from independent capabilities: `charts` is a
 * serializer (the wire shape) + a custom
 * {@see \haddowg\JsonApiBundle\Examples\MusicCatalog\Provider\ChartProvider} (the
 * data), and nothing else — so it is read-only and fetchable, the classic
 * serialize-plus-fetch type. Its `operations` allow-list opens exactly
 * `GET /charts` and `GET /charts/{id}`; a standalone serializer defaults to
 * **none**, so without that list it would expose no endpoints at all — the
 * asymmetry against an `AbstractResource`, which defaults to **all five**.
 */
#[Group('spec:fetching')]
final class CapabilityCompositionTest extends MusicCatalogKernelTestCase
{
    #[Test]
    public function theStandaloneSerializerFetchesACollection(): void
    {
        $response = $this->handle('/charts');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $data = $this->decode($response)['data'] ?? null;
        self::assertIsList($data);
        self::assertNotEmpty($data);

        $first = $data[0];
        self::assertIsArray($first);
        self::assertSame('charts', $first['type'] ?? null);

        $attributes = $first['attributes'] ?? null;
        self::assertIsArray($attributes);
        self::assertSame('Weekly Top', $attributes['name'] ?? null);
        // The hand-written serializer renders the entries list verbatim.
        self::assertIsArray($attributes['entries'] ?? null);
    }

    #[Test]
    public function theStandaloneSerializerFetchesOne(): void
    {
        $data = $this->decode($this->handle('/charts/1'))['data'] ?? null;
        self::assertIsArray($data);
        self::assertSame('charts', $data['type'] ?? null);
        self::assertSame('1', $data['id'] ?? null);

        $attributes = $data['attributes'] ?? null;
        self::assertIsArray($attributes);
        self::assertSame('2024-W03', $attributes['period'] ?? null);
    }

    #[Test]
    public function aStandaloneSerializerExposesOnlyTheTwoFetchRoutes(): void
    {
        // charts lists only FetchCollection + FetchOne, so exactly two routes are
        // emitted and no write route exists. Reachability is asserted against the
        // booted route collection rather than by issuing a request to an unrouted
        // verb (which would surface as a framework-logged exception PHPUnit's strict
        // mode flags as risky — the convention MultiServerTest follows).
        $names = $this->routesByName();

        self::assertArrayHasKey('jsonapi.charts.index', $names);
        self::assertArrayHasKey('jsonapi.charts.show', $names);
        self::assertArrayNotHasKey('jsonapi.charts.create', $names);
        self::assertArrayNotHasKey('jsonapi.charts.update', $names);
        self::assertArrayNotHasKey('jsonapi.charts.delete', $names);
    }

    #[Test]
    public function anAbstractResourceDefaultsToAllFiveOperations(): void
    {
        // The other side of the asymmetry: `albums` is an AbstractResource, so it
        // defaults to all five operations — every CRUD route is emitted, including the
        // write routes a standalone serializer omits by default.
        $names = $this->routesByName();

        self::assertArrayHasKey('jsonapi.albums.index', $names);
        self::assertArrayHasKey('jsonapi.albums.show', $names);
        self::assertArrayHasKey('jsonapi.albums.create', $names);
        self::assertArrayHasKey('jsonapi.albums.update', $names);
        self::assertArrayHasKey('jsonapi.albums.delete', $names);
    }

    #[Test]
    public function theCreateRouteOfAResourceIsReachableWhereAStandaloneSerializerHasNone(): void
    {
        // The albums create route exists and is reachable — an empty body fails
        // validation with a route-scoped 422 (the route is there), proving the
        // asymmetry end-to-end without probing an unrouted verb.
        $response = $this->handle('/albums', 'POST', [
            'data' => ['type' => 'albums', 'attributes' => []],
        ]);

        self::assertSame(422, $response->getStatusCode(), (string) $response->getContent());
    }

    /**
     * The booted router's routes keyed by name.
     *
     * @return array<string, \Symfony\Component\Routing\Route>
     */
    private function routesByName(): array
    {
        $router = static::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);

        return $router->getRouteCollection()->all();
    }
}
