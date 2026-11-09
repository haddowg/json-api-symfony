<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApi\OpenApi\OpenApiProjector;
use haddowg\JsonApiBundle\OpenApi\Metadata\MetadataSource;
use haddowg\JsonApiBundle\Tests\Functional\App\RelatedOnly\RelatedOnlyTestKernel;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Routing\RouterInterface;

/**
 * A `relatedOnly` type serves no endpoint of its own: it renders as a related and an
 * included resource without links to routes that don't exist, can be an action's
 * request document, and the OpenAPI document gives it no paths.
 */
final class RelatedOnlyTypeTest extends JsonApiFunctionalTestCase
{
    protected static function getKernelClass(): string
    {
        return RelatedOnlyTestKernel::class;
    }

    #[Test]
    public function itHasNoRoutesOfItsOwn(): void
    {
        $router = static::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);

        $paths = [];
        foreach ($router->getRouteCollection() as $route) {
            $paths[] = $route->getPath();
        }

        self::assertContains('/records/{id}/{relationship}', $paths);
        self::assertSame([], \array_values(\array_filter($paths, static fn(string $path): bool => \str_starts_with($path, '/imprints'))));
    }

    #[Test]
    public function anIncludedResourceRendersNoLinksToItsOwnEndpoints(): void
    {
        $response = $this->handle('/records/r1?include=imprint');
        self::assertSame(200, $response->getStatusCode());
        $document = $this->decode($response);

        self::assertSame(['self' => ['href' => 'https://example.test/records/r1']], self::at($document, 'data', 'links'));
        self::assertNotNull(self::at($document, 'data', 'relationships', 'imprint', 'links'));

        self::assertSame('imprints', self::at($document, 'included', 0, 'type'));
        self::assertNull(self::at($document, 'included', 0, 'links'));
        self::assertSame(['data' => ['type' => 'imprints', 'id' => 'i0']], self::at($document, 'included', 0, 'relationships', 'parent'));
    }

    #[Test]
    public function theRelatedEndpointServesIt(): void
    {
        $response = $this->handle('/records/r1/imprint');
        self::assertSame(200, $response->getStatusCode());
        $document = $this->decode($response);

        self::assertSame('i1', self::at($document, 'data', 'id'));
        self::assertNull(self::at($document, 'data', 'links'));
        self::assertSame(['href' => 'https://example.test/records/r1/imprint'], self::at($document, 'links', 'self'));
    }

    #[Test]
    public function anActionTakesItAsItsRequestDocument(): void
    {
        $response = $this->handle('/records/r1/-actions/reimprint', 'POST', [
            'data' => ['type' => 'imprints', 'attributes' => ['name' => 'Fontana']],
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('i9', self::at($this->decode($response), 'data', 'relationships', 'imprint', 'data', 'id'));
    }

    #[Test]
    public function theDocumentGivesItNoPathsAndNoSelfLink(): void
    {
        $source = static::getContainer()->get(MetadataSource::class);
        self::assertInstanceOf(MetadataSource::class, $source);
        $document = (new OpenApiProjector())->project($source->forServer())->toArray();

        $paths = self::at($document, 'paths');
        self::assertIsArray($paths);
        self::assertSame([], \array_values(\array_filter(\array_keys($paths), static fn(string $path): bool => \str_starts_with($path, '/imprints'))));
        self::assertArrayHasKey('/records/{id}/imprint', $paths);

        self::assertSame('#/components/schemas/ImprintsResourceLinks', self::at($document, 'components', 'schemas', 'ImprintsResource', 'properties', 'links', '$ref'));
        self::assertNull(self::at($document, 'components', 'schemas', 'ImprintsResourceLinks', 'properties', 'self'));
        self::assertNotNull(self::at($document, 'components', 'schemas', 'ImprintsCreateRequest'));
        self::assertNull(self::at($paths, '/records/{id}/imprint', 'get', 'responses', '200', 'content', 'application/vnd.api+json', 'example', 'data', 'links'));
    }

    private static function at(mixed $value, string|int ...$keys): mixed
    {
        foreach ($keys as $key) {
            if (!\is_array($value) || !\array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }
}
