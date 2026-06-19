<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\OpenApi\ArtifactStore;
use haddowg\JsonApiBundle\OpenApi\DocumentFactory;
use haddowg\JsonApiBundle\OpenApi\DocumentWarmer;
use haddowg\JsonApiBundle\Tests\Functional\App\OpenApi\OpenApiTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\OpenApi\StampDecorator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * The wholesale-customisation decorator witness (design §5, D7 — bundle ADR 0080): a
 * registered {@see StampDecorator} (autoconfigured onto the OpenAPI factory tag in
 * {@see OpenApiTestKernel}) appends a uniquely-named tag to the built document. The test
 * proves the decorator runs on **both** build paths the bundle exposes — the controller's
 * served document (lazy-build) and the warmer's pre-built artifact — because both flow
 * through the {@see DocumentFactory} where the decorator chain is applied.
 */
final class OpenApiDecoratorTest extends JsonApiFunctionalTestCase
{
    protected static function getKernelClass(): string
    {
        return OpenApiTestKernel::class;
    }

    #[Test]
    #[Group('spec:openapi')]
    public function theDecoratorMutatesTheServedDocument(): void
    {
        $document = $this->decode($this->handle('/docs.json'));

        self::assertContains(StampDecorator::TAG_NAME, $this->tagNames($document));
    }

    #[Test]
    #[Group('spec:openapi')]
    public function theDecoratorMutatesTheWarmedArtifact(): void
    {
        $kernel = static::$kernel;
        self::assertNotNull($kernel);
        $container = static::getContainer();

        $warmer = $container->get(DocumentWarmer::class);
        \assert($warmer instanceof DocumentWarmer);
        $warmer->warmUp($kernel->getCacheDir());

        $store = $container->get(ArtifactStore::class);
        \assert($store instanceof ArtifactStore);
        $artifact = $store->read('default');
        self::assertIsString($artifact);

        $decoded = \json_decode($artifact, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        // The warmed artifact carries the decorator's stamp too (same DocumentFactory).
        self::assertContains(StampDecorator::TAG_NAME, $this->tagNames($decoded));

        // And the controller serves that very (decorated) artifact O(file read).
        $response = $kernel->handle(Request::create('/docs.json', 'GET'), HttpKernelInterface::MAIN_REQUEST, true);
        self::assertSame($artifact, (string) $response->getContent());
    }

    #[Test]
    #[Group('spec:openapi')]
    public function theDecoratorReceivesTheServerName(): void
    {
        $document = $this->decode($this->handle('/docs.json'));

        // The stamp's description carries the server name the document was built for.
        foreach ($this->tags($document) as $tag) {
            if (($tag['name'] ?? null) === StampDecorator::TAG_NAME) {
                self::assertSame('Stamped by the decorator for server: default', $tag['description'] ?? null);

                return;
            }
        }

        self::fail('The decorator tag was not present in the served document.');
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return list<array<string, mixed>>
     */
    private function tags(array $document): array
    {
        $tags = $document['tags'] ?? [];
        self::assertIsArray($tags);

        $out = [];
        foreach ($tags as $tag) {
            self::assertIsArray($tag);
            /** @var array<string, mixed> $tag */
            $out[] = $tag;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return list<mixed>
     */
    private function tagNames(array $document): array
    {
        return \array_map(static fn(array $tag): mixed => $tag['name'] ?? null, $this->tags($document));
    }
}
