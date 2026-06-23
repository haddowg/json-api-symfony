<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\ReadOnlyResourceTestKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Routing\RouterInterface;

/**
 * The `readOnly` shorthand witness (E1): a `catalogues` resource declared
 * `#[AsJsonApiResource(readOnly: true)]` exposes exactly the two fetch operations
 * — `GET /catalogues` and `GET /catalogues/{id}` — and no write routes
 * (`POST`/`PATCH`/`DELETE`), without importing the `Operation` enum. Served by a
 * provider only (no persister), so the servability warm-up guard passes.
 * Storage-orthogonal, so witnessed on the in-memory kernel only.
 */
final class ReadOnlyResourceTest extends JsonApiFunctionalTestCase
{
    protected static function getKernelClass(): string
    {
        return ReadOnlyResourceTestKernel::class;
    }

    #[Test]
    #[Group('spec:fetching')]
    public function theReadOnlyTypeServesItsFetchCollectionEndpoint(): void
    {
        $response = $this->handle('/catalogues');
        self::assertSame(200, $response->getStatusCode());

        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);

        $first = $data[0] ?? null;
        self::assertIsArray($first);
        self::assertSame('catalogues', $first['type'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching')]
    public function theReadOnlyTypeServesItsFetchOneEndpoint(): void
    {
        $response = $this->handle('/catalogues/1');
        self::assertSame(200, $response->getStatusCode());

        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);
        self::assertSame('catalogues', $data['type'] ?? null);
        self::assertSame('1', $data['id'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching')]
    public function theReadOnlyTypeEmitsOnlyTheTwoFetchRoutes(): void
    {
        $router = static::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);

        $names = \array_keys($router->getRouteCollection()->all());

        self::assertContains('jsonapi.catalogues.index', $names);
        self::assertContains('jsonapi.catalogues.show', $names);

        self::assertNotContains('jsonapi.catalogues.create', $names);
        self::assertNotContains('jsonapi.catalogues.update', $names);
        self::assertNotContains('jsonapi.catalogues.delete', $names);
    }

    #[Test]
    #[Group('spec:crud')]
    public function aWriteToTheReadOnlyTypeIsUnrouted(): void
    {
        // The collection path exists for GET only, so a POST is a router-native 405
        // — no Create route was emitted and no handler is reached.
        self::assertSame(405, $this->handle('/catalogues', 'POST', [
            'data' => ['type' => 'catalogues', 'attributes' => ['name' => 'Reissues']],
        ])->getStatusCode());

        // Likewise the member path exists for GET only — PATCH/DELETE are 405.
        self::assertSame(405, $this->handle('/catalogues/1', 'PATCH', [
            'data' => ['type' => 'catalogues', 'id' => '1', 'attributes' => ['name' => 'Renamed']],
        ])->getStatusCode());
        self::assertSame(405, $this->handle('/catalogues/1', 'DELETE')->getStatusCode());
    }
}
