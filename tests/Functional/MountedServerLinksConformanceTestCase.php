<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\OpenApi\DocumentFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * Links from a server whose routes are imported under `/api/v5` with an empty
 * `base_uri`. Core bases every link on the request origin alone,
 * which knows nothing of the import prefix; the bundle hands core the effective base
 * `origin + mount` per request, so resource links gain the mount and document links
 * (which reuse the request path) carry it exactly once.
 *
 * The same assertions run against the in-memory provider
 * ({@see InMemoryMountedServerLinksTest}) and the Doctrine provider
 * ({@see DoctrineMountedServerLinksTest}), both over the shared
 * {@see \haddowg\JsonApiBundle\Tests\Functional\App\ArticleFixtures}.
 */
abstract class MountedServerLinksConformanceTestCase extends JsonApiFunctionalTestCase
{
    private const string BASE = 'http://localhost/api/v5';

    #[Test]
    #[Group('spec:document-links')]
    public function aResourceAndItsDocumentLinkCarryTheMountOnce(): void
    {
        $document = $this->fetch('/api/v5/articles/1');

        self::assertSame(['self' => ['href' => self::BASE . '/articles/1']], $document['links'] ?? null);
        self::assertSame(['self' => ['href' => self::BASE . '/articles/1']], $this->dataOf($document)['links'] ?? null);
    }

    #[Test]
    #[Group('spec:document-links')]
    public function relationshipLinksOnAResourceCarryTheMount(): void
    {
        $relationships = $this->dataOf($this->fetch('/api/v5/articles/1'))['relationships'] ?? null;
        self::assertIsArray($relationships);

        $author = $relationships['author'] ?? null;
        self::assertIsArray($author);
        self::assertSame(
            [
                'self' => ['href' => self::BASE . '/articles/1/relationships/author'],
                'related' => ['href' => self::BASE . '/articles/1/author'],
            ],
            $author['links'] ?? null,
        );
    }

    #[Test]
    #[Group('spec:document-links')]
    public function anIncludedResourceLinkCarriesTheMount(): void
    {
        $document = $this->fetch('/api/v5/articles/1?include=author');

        $included = $document['included'] ?? null;
        self::assertIsArray($included);
        self::assertCount(1, $included);
        self::assertIsArray($included[0]);
        self::assertSame(['self' => ['href' => self::BASE . '/authors/1']], $included[0]['links'] ?? null);
    }

    #[Test]
    #[Group('spec:document-links')]
    public function collectionPaginationLinksCarryTheMountOnce(): void
    {
        $document = $this->fetch('/api/v5/articles?page[number]=2&page[size]=2');

        $links = $document['links'] ?? null;
        self::assertIsArray($links);

        foreach (['self', 'first', 'prev', 'next'] as $name) {
            self::assertArrayHasKey($name, $links);
            self::assertIsArray($links[$name]);
            self::assertIsString($links[$name]['href'] ?? null);
            self::assertStringStartsWith(self::BASE . '/articles?', $links[$name]['href'], $name);
        }

        self::assertSame(['href' => self::BASE . '/articles?page%5Bnumber%5D=3&page%5Bsize%5D=2'], $links['next']);

        foreach ($this->dataListOf($document) as $resource) {
            self::assertIsString($resource['id'] ?? null);
            self::assertSame(['self' => ['href' => self::BASE . '/articles/' . $resource['id']]], $resource['links'] ?? null);
        }
    }

    #[Test]
    #[Group('spec:document-links')]
    public function aRelationshipEndpointCarriesTheMountOnce(): void
    {
        $document = $this->fetch('/api/v5/articles/1/relationships/pagedComments?page[size]=1');

        $links = $document['links'] ?? null;
        self::assertIsArray($links);
        self::assertSame(['href' => self::BASE . '/articles/1/relationships/pagedComments'], $links['self'] ?? null);
        self::assertSame(['href' => self::BASE . '/articles/1/pagedComments'], $links['related'] ?? null);
        self::assertSame(
            ['href' => self::BASE . '/articles/1/relationships/pagedComments?page%5Bnumber%5D=2&page%5Bsize%5D=1'],
            $links['next'] ?? null,
        );
    }

    #[Test]
    #[Group('spec:document-links')]
    public function aRelatedEndpointCarriesTheMountOnce(): void
    {
        $document = $this->fetch('/api/v5/articles/1/author');

        $links = $document['links'] ?? null;
        self::assertIsArray($links);
        self::assertSame(['href' => self::BASE . '/articles/1/author'], $links['self'] ?? null);
        self::assertSame(['self' => ['href' => self::BASE . '/authors/1']], $this->dataOf($document)['links'] ?? null);
    }

    #[Test]
    #[Group('spec:document-links')]
    public function theMountFollowsTheRequestHost(): void
    {
        $document = $this->fetch('https://tenant.example/api/v5/articles/1');

        self::assertSame(['self' => ['href' => 'https://tenant.example/api/v5/articles/1']], $document['links'] ?? null);
        self::assertSame(['self' => ['href' => 'https://tenant.example/api/v5/articles/1']], $this->dataOf($document)['links'] ?? null);
    }

    #[Test]
    #[Group('spec:crud')]
    public function theCreateLocationCarriesTheMount(): void
    {
        $response = $this->handle('/api/v5/articles', 'POST', [
            'data' => [
                'type' => 'articles',
                'attributes' => [
                    'title' => 'A brand new article',
                    'body' => 'Fresh content.',
                    'category' => 'news',
                ],
            ],
        ]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());

        $data = $this->dataOf($this->decode($response));
        self::assertIsString($data['id'] ?? null);

        $expected = self::BASE . '/articles/' . $data['id'];
        self::assertSame(['self' => ['href' => $expected]], $data['links'] ?? null);
        self::assertSame($expected, $response->headers->get('Location'));
    }

    #[Test]
    #[Group('spec:openapi')]
    public function theOpenApiDocumentAdvertisesTheMountAsItsServer(): void
    {
        $factory = static::getContainer()->get(DocumentFactory::class);
        self::assertInstanceOf(DocumentFactory::class, $factory);

        $document = $factory->forServer()->toArray();

        self::assertSame([['url' => '/api/v5']], $document['servers'] ?? null);
        self::assertIsArray($document['paths'] ?? null);
        self::assertArrayHasKey('/articles/{id}', $document['paths']);
    }

    /**
     * @return array<string, mixed>
     */
    protected function fetch(string $uri): array
    {
        $response = $this->handle($uri);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        /** @var array<string, mixed> */
        return $this->decode($response);
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    protected function dataOf(array $document): array
    {
        $data = $document['data'] ?? null;
        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return list<array<string, mixed>>
     */
    private function dataListOf(array $document): array
    {
        $data = $document['data'] ?? null;
        self::assertIsArray($data);
        self::assertNotSame([], $data);

        $resources = [];
        foreach ($data as $resource) {
            self::assertIsArray($resource);
            /** @var array<string, mixed> $resource */
            $resources[] = $resource;
        }

        return $resources;
    }
}
