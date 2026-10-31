<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\OpenApiServe;

use haddowg\JsonApiBundle\OpenApi\ArtifactStore;
use haddowg\JsonApiBundle\OpenApi\DocumentFactory;
use haddowg\JsonApiBundle\Tests\Functional\App\OpenApi\Serve\FeatureGateDecorator;
use haddowg\JsonApiBundle\Tests\Functional\App\OpenApi\Serve\FeatureGateSchemaDecorator;
use haddowg\JsonApiBundle\Tests\Functional\App\OpenApi\Serve\HostServersDecorator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Serve decorators that all supply a cache key: the document varies per
 * host, orphaned components are pruned, and a matching `If-None-Match` is answered before
 * any decorator runs.
 */
final class OpenApiServeKeyedTest extends OpenApiServeTestCase
{
    protected static function environment(): string
    {
        return 'keyed';
    }

    #[Test]
    #[Group('spec:openapi')]
    public function theServersFollowTheRequestHost(): void
    {
        self::assertSame([['url' => 'https://one.test/api']], $this->json($this->get('/docs.json', 'one.test'))['servers'] ?? null);
        self::assertSame([['url' => 'https://two.test/api']], $this->json($this->get('/docs.json', 'two.test'))['servers'] ?? null);
    }

    #[Test]
    #[Group('spec:openapi')]
    public function operationsOutsideTheTenantsFeaturesAreRemovedAndWhatTheyAloneUsedIsPruned(): void
    {
        $full = $this->get('/docs.json', 'full.tenant.test');
        $limited = $this->get('/docs.json', 'tenant.test');
        $this->assertValidOpenApi($full);
        $this->assertValidOpenApi($limited);

        $fullDoc = $this->json($full);
        $limitedDoc = $this->json($limited);

        $fullPaths = self::arr($fullDoc['paths'] ?? null);
        $limitedPaths = self::arr($limitedDoc['paths'] ?? null);
        self::assertArrayHasKey('/categories', $fullPaths);
        self::assertArrayHasKey('post', self::arr($fullPaths['/products']));

        // The categories endpoints are gone, path items and all, and so is products' create.
        self::assertArrayNotHasKey('/categories', $limitedPaths);
        self::assertArrayNotHasKey('/categories/{id}', $limitedPaths);
        self::assertArrayNotHasKey('post', self::arr($limitedPaths['/products']));
        self::assertArrayHasKey('get', self::arr($limitedPaths['/products']));

        $fullSchemas = self::arr(self::arr($fullDoc['components'] ?? null)['schemas'] ?? null);
        $limitedSchemas = self::arr(self::arr($limitedDoc['components'] ?? null)['schemas'] ?? null);

        // Only the removed operations used these.
        foreach (['CategoriesCreateRequest', 'CategoriesCreateAttributes', 'CategoriesUpdateRequest', 'CategoriesUpdateAttributes', 'CategoriesDocument', 'CategoriesCollection', 'ProductsCreateRequest', 'ProductsCreateAttributes'] as $name) {
            self::assertArrayHasKey($name, $fullSchemas);
            self::assertArrayNotHasKey($name, $limitedSchemas, $name);
        }

        // Products' related endpoints still return categories, so these stay.
        foreach (['CategoriesResource', 'ProductsTagsRelatedCollection', 'ProductsCategoryRelatedDocument', 'CategoriesAttributes', 'ProductsResource', 'Meta', 'Links', 'ErrorDocument'] as $name) {
            self::assertArrayHasKey($name, $limitedSchemas, $name);
        }

        // The tag only categories operations used goes; the configured one stays.
        $tagNames = static fn(array $doc): array => \array_map(
            static fn(mixed $tag): mixed => self::arr($tag)['name'] ?? null,
            self::arr($doc['tags'] ?? []),
        );
        self::assertContains('Categories', $tagNames($fullDoc));
        self::assertNotContains('Categories', $tagNames($limitedDoc));
        self::assertContains('Catalog', $tagNames($limitedDoc));
    }

    #[Test]
    #[Group('spec:openapi')]
    public function theEtagHashesTheArtifactHashAndEveryCacheKey(): void
    {
        $this->warm();
        $artifactHash = $this->store()->readHash('default');
        self::assertIsString($artifactHash);

        $response = $this->get('/docs.json', 'one.test');

        // Both decorators share the default priority, so they run (and key) in reverse
        // registration order, as the build-time decorators do.
        $keys = \json_encode([
            [FeatureGateDecorator::class, ['v' => 1, 'features' => ['catalog']]],
            [HostServersDecorator::class, 'one.test'],
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        self::assertSame(self::quoted(ArtifactStore::hash($artifactHash . "\n" . $keys)), $response->getEtag());
        self::assertNotSame(self::quoted(ArtifactStore::hash((string) $response->getContent())), $response->getEtag());

        self::assertNotSame($response->getEtag(), $this->get('/docs.json', 'two.test')->getEtag());
        self::assertSame($response->getEtag(), $this->get('/docs.json', 'one.test')->getEtag());
    }

    #[Test]
    #[Group('spec:openapi')]
    public function aMatchingIfNoneMatchIsAnsweredWithoutDecorating(): void
    {
        $this->warm();
        $etag = $this->etagOf($this->get('/docs.json', 'one.test'));
        self::assertSame(1, FeatureGateDecorator::$decorated);

        foreach ([$etag, 'W/' . $etag] as $ifNoneMatch) {
            $response = $this->get('/docs.json', 'one.test', ['If-None-Match' => $ifNoneMatch]);
            self::assertSame(304, $response->getStatusCode());
            self::assertSame($etag, $response->getEtag());
            self::assertNotNull($response->headers->get('Cache-Control'));
        }

        self::assertSame(1, FeatureGateDecorator::$decorated);
        self::assertSame(3, FeatureGateDecorator::$keyed);

        // Another host's ETag does not match.
        self::assertSame(200, $this->get('/docs.json', 'two.test', ['If-None-Match' => $etag])->getStatusCode());
        self::assertSame(2, FeatureGateDecorator::$decorated);
    }

    #[Test]
    #[Group('spec:openapi')]
    public function anUnwarmedCacheStillServesTheDecoratedDocument(): void
    {
        // A kernel booted without the warmer runs only the lazy build, wherever an earlier
        // test left the cache; both paths must agree.
        $before = $this->get('/docs.json', 'one.test');
        $this->warm();
        $after = $this->get('/docs.json', 'one.test');

        self::assertSame($before->getContent(), $after->getContent());
        self::assertSame($before->getEtag(), $after->getEtag());
    }

    #[Test]
    #[Group('spec:openapi')]
    public function theCliExportIsNotDecorated(): void
    {
        $tester = $this->command('json-api:openapi:export');
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();

        $exported = \json_decode($tester->getDisplay(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($exported);
        self::assertSame([['url' => 'https://catalog.test']], $exported['servers'] ?? null);
        self::assertArrayHasKey('/categories', self::arr($exported['paths'] ?? null));
        self::assertArrayHasKey('CategoriesCreateRequest', self::arr(self::arr($exported['components'] ?? null)['schemas'] ?? null));

        $documents = static::getContainer()->get(DocumentFactory::class);
        \assert($documents instanceof DocumentFactory);
        self::assertSame($documents->forServer()->toArray(), $exported);

        $schemas = $this->command('json-api:json-schema:export');
        $schemas->execute([]);
        $schemas->assertCommandIsSuccessful();
        self::assertStringContainsString('categories', $schemas->getDisplay());
        self::assertSame(0, FeatureGateDecorator::$decorated + FeatureGateSchemaDecorator::$decorated);
    }

    #[Test]
    #[Group('spec:openapi')]
    public function theSchemasAggregateIsDecoratedAndAnsweredConditionallyTheSameWay(): void
    {
        $this->warm();
        $artifactHash = $this->store()->readSchemaAggregateHash('default');
        self::assertIsString($artifactHash);

        $full = $this->get('/schemas.json', 'full.tenant.test');
        $limited = $this->get('/schemas.json', 'tenant.test');
        self::assertArrayHasKey('categories', $this->json($full));
        self::assertArrayNotHasKey('categories', $this->json($limited));
        self::assertArrayHasKey('products', $this->json($limited));
        self::assertSame(2, FeatureGateSchemaDecorator::$decorated);

        $keys = \json_encode([[FeatureGateSchemaDecorator::class, ['v' => 1, 'features' => ['catalog']]]], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
        self::assertSame(self::quoted(ArtifactStore::hash($artifactHash . "\n" . $keys)), $limited->getEtag());

        $notModified = $this->get('/schemas.json', 'tenant.test', ['If-None-Match' => 'W/' . $this->etagOf($limited)]);
        self::assertSame(304, $notModified->getStatusCode());
        self::assertSame(2, FeatureGateSchemaDecorator::$decorated);
    }

    private function command(string $name): CommandTester
    {
        $kernel = static::$kernel;
        self::assertNotNull($kernel);

        return new CommandTester((new Application($kernel))->find($name));
    }
}
