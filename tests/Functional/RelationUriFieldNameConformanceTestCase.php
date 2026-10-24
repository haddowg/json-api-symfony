<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\OpenApi\DocumentFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * The relation URI field name suite, run identically against the in-memory provider
 * ({@see InMemoryRelationUriFieldNameTest}) and the Doctrine provider
 * ({@see DoctrineRelationUriFieldNameTest}).
 *
 * The shared {@see App\Resource\UriFieldNameArticleResource} serves `remarks` at
 * `article-remarks` and `writer` at `written-by`. The links and the OpenAPI document
 * name only that segment; a request to the relation's name is still answered, so a
 * client written before the rename keeps working; any other segment is a `404`.
 */
abstract class RelationUriFieldNameConformanceTestCase extends JsonApiFunctionalTestCase
{
    private const string BASE_URI = 'https://example.test';

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function theLinksNameTheUriFieldName(): void
    {
        $response = $this->handle('/articles/1');
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        $relationships = $this->at($this->decode($response), 'data', 'relationships');
        self::assertIsArray($relationships);

        // The relationship member keeps the relation's name; only its URLs move.
        self::assertSame(
            [
                'self' => ['href' => self::BASE_URI . '/articles/1/relationships/article-remarks'],
                'related' => ['href' => self::BASE_URI . '/articles/1/article-remarks'],
            ],
            $this->at($relationships, 'remarks', 'links'),
        );
        self::assertSame(
            self::BASE_URI . '/articles/1/written-by',
            $this->at($relationships, 'writer', 'links', 'related', 'href'),
        );
    }

    #[Test]
    #[Group('spec:fetching-resources')]
    public function theRelatedEndpointAnswersAtTheUriFieldNameAndTheName(): void
    {
        foreach (['article-remarks', 'remarks'] as $segment) {
            $response = $this->handle('/articles/1/' . $segment);
            self::assertSame(Response::HTTP_OK, $response->getStatusCode(), $segment . ': ' . $response->getContent());

            self::assertSame(['1', '2'], $this->ids($this->decode($response)['data'] ?? null), $segment);
        }
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function theRelationshipEndpointAnswersAtTheUriFieldNameAndTheName(): void
    {
        foreach (['article-remarks', 'remarks'] as $segment) {
            $response = $this->handle('/articles/1/relationships/' . $segment);
            self::assertSame(Response::HTTP_OK, $response->getStatusCode(), $segment . ': ' . $response->getContent());

            $document = $this->decode($response);
            self::assertSame(['1', '2'], $this->ids($document['data'] ?? null), $segment);
            // The document's own links name the URI field name whichever path was called.
            self::assertSame(
                self::BASE_URI . '/articles/1/article-remarks',
                $this->at($document, 'links', 'related', 'href'),
                $segment,
            );
        }
    }

    #[Test]
    #[Group('spec:crud-updating-relationships')]
    public function aToOneRelationshipIsReplacedAtTheUriFieldNameAndTheName(): void
    {
        // Article 1 starts with author 1: move it to 2 at the URI field name, then
        // back to 1 at the name.
        foreach (['written-by' => '2', 'writer' => '1'] as $segment => $authorId) {
            $response = $this->handle('/articles/1/relationships/' . $segment, 'PATCH', [
                'data' => ['type' => 'authors', 'id' => $authorId],
            ]);
            self::assertSame(Response::HTTP_OK, $response->getStatusCode(), $segment . ': ' . $response->getContent());

            $related = $this->handle('/articles/1/written-by');
            self::assertSame(Response::HTTP_OK, $related->getStatusCode(), (string) $related->getContent());
            self::assertSame($authorId, $this->at($this->decode($related), 'data', 'id'), $segment);
        }
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function aSegmentNoRelationAnswersToIs404(): void
    {
        foreach (['/articles/1/nothing', '/articles/1/relationships/nothing'] as $path) {
            $response = $this->handle($path);
            self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode(), $path . ': ' . $response->getContent());
        }
    }

    #[Test]
    #[Group('ext:atomic')]
    public function anAtomicHrefAddressesTheRelationAtTheUriFieldNameAndTheName(): void
    {
        foreach (['written-by' => '2', 'writer' => '1'] as $segment => $authorId) {
            $response = $this->atomic([[
                'op' => 'update',
                'href' => '/articles/1/relationships/' . $segment,
                'data' => ['type' => 'authors', 'id' => $authorId],
            ]]);
            self::assertContains($response->getStatusCode(), [Response::HTTP_OK, Response::HTTP_NO_CONTENT], $segment . ': ' . $response->getContent());

            $related = $this->handle('/articles/1/written-by');
            self::assertSame($authorId, $this->at($this->decode($related), 'data', 'id'), $segment);
        }
    }

    #[Test]
    #[Group('ext:atomic')]
    public function anAtomicHrefToAnUnknownSegmentFailsTheBatch(): void
    {
        $response = $this->atomic([[
            'op' => 'update',
            'href' => '/articles/1/relationships/nothing',
            'data' => ['type' => 'authors', 'id' => '2'],
        ]]);
        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode(), (string) $response->getContent());
    }

    #[Test]
    #[Group('spec:openapi')]
    public function theOpenApiDocumentKeysThePathsByTheUriFieldName(): void
    {
        $factory = static::getContainer()->get(DocumentFactory::class);
        self::assertInstanceOf(DocumentFactory::class, $factory);
        $paths = $factory->forServer()->toArray()['paths'] ?? null;
        self::assertIsArray($paths);

        self::assertArrayHasKey('/articles/{id}/article-remarks', $paths);
        self::assertArrayHasKey('/articles/{id}/relationships/article-remarks', $paths);
        self::assertArrayHasKey('/articles/{id}/written-by', $paths);
        // The name path still answers, but it is not part of the documented API.
        self::assertArrayNotHasKey('/articles/{id}/remarks', $paths);
        self::assertArrayNotHasKey('/articles/{id}/relationships/remarks', $paths);
        self::assertArrayNotHasKey('/articles/{id}/writer', $paths);
    }

    /**
     * The value at `$keys` inside a decoded document, `null` when any level is missing.
     */
    private function at(mixed $node, string ...$keys): mixed
    {
        foreach ($keys as $key) {
            if (!\is_array($node)) {
                return null;
            }
            $node = $node[$key] ?? null;
        }

        return $node;
    }

    /**
     * @return list<string>
     */
    private function ids(mixed $data): array
    {
        self::assertIsArray($data);
        $ids = [];
        foreach ($data as $member) {
            self::assertIsArray($member);
            $id = $member['id'] ?? null;
            self::assertIsString($id);
            $ids[] = $id;
        }
        \sort($ids);

        return $ids;
    }

    /**
     * @param list<array<string, mixed>> $operations
     */
    private function atomic(array $operations): Response
    {
        $kernel = static::$kernel;
        self::assertNotNull($kernel);

        $ext = 'application/vnd.api+json; ext="https://jsonapi.org/ext/atomic"';
        $request = Request::create(
            '/operations',
            'POST',
            server: ['CONTENT_TYPE' => $ext, 'HTTP_ACCEPT' => $ext],
            content: \json_encode(['atomic:operations' => $operations], \JSON_THROW_ON_ERROR),
        );
        $response = $kernel->handle($request, HttpKernelInterface::MAIN_REQUEST, true);
        $this->restoreHandlers();

        return $response;
    }
}
