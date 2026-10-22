<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\OpenApi\DocumentFactory;
use haddowg\JsonApiBundle\Tests\Functional\App\ConfiguredBaseMountedInMemoryTestKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * A configured `base_uri` that carries the server's mount (`/api/v5`) is used
 * verbatim: the recorded mount never adds to it, and the request host never
 * replaces it. Core counts the configured base's path as the mount, so document
 * links carry it once (bundle ADR 0121, core ADR 0140).
 */
final class ConfiguredBaseUnderAPrefixTest extends JsonApiFunctionalTestCase
{
    private const string BASE = 'https://api.example.test/api/v5';

    protected static function getKernelClass(): string
    {
        return ConfiguredBaseMountedInMemoryTestKernel::class;
    }

    #[Test]
    #[Group('spec:document-links')]
    public function resourceAndDocumentLinksUseTheConfiguredBase(): void
    {
        $response = $this->handle('https://tenant.example/api/v5/articles/1');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $document = $this->decode($response);
        self::assertSame(['self' => self::BASE . '/articles/1'], $document['links'] ?? null);

        $data = $document['data'] ?? null;
        self::assertIsArray($data);
        self::assertSame(['self' => self::BASE . '/articles/1'], $data['links'] ?? null);

        $relationships = $data['relationships'] ?? null;
        self::assertIsArray($relationships);
        $author = $relationships['author'] ?? null;
        self::assertIsArray($author);
        self::assertSame(
            [
                'self' => self::BASE . '/articles/1/relationships/author',
                'related' => self::BASE . '/articles/1/author',
            ],
            $author['links'] ?? null,
        );
    }

    #[Test]
    #[Group('spec:document-links')]
    public function paginationLinksCarryTheMountOnce(): void
    {
        $response = $this->handle('/api/v5/articles?page[size]=2');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $links = $this->decode($response)['links'] ?? null;
        self::assertIsArray($links);
        self::assertSame(self::BASE . '/articles?page%5Bnumber%5D=1&page%5Bsize%5D=2', $links['self'] ?? null);
        self::assertSame(self::BASE . '/articles?page%5Bnumber%5D=2&page%5Bsize%5D=2', $links['next'] ?? null);
    }

    #[Test]
    #[Group('spec:crud')]
    public function theCreateLocationUsesTheConfiguredBase(): void
    {
        $response = $this->handle('/api/v5/articles', 'POST', [
            'data' => [
                'type' => 'articles',
                'attributes' => ['title' => 'A brand new article', 'body' => 'Fresh content.', 'category' => 'news'],
            ],
        ]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());

        self::assertSame(self::BASE . '/articles/6', $response->headers->get('Location'));
    }

    #[Test]
    #[Group('spec:openapi')]
    public function theOpenApiServerIsTheConfiguredBase(): void
    {
        self::assertSame([['url' => self::BASE]], $this->openApi()['servers'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private function openApi(?string $server = null): array
    {
        $factory = static::getContainer()->get(DocumentFactory::class);
        self::assertInstanceOf(DocumentFactory::class, $factory);

        return $factory->forServer($server)->toArray();
    }
}
