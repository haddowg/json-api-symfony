<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Testing\SchemaConformanceTrait;
use haddowg\JsonApiBundle\Testing\SchemaDocumentKind;
use haddowg\JsonApiBundle\Tests\Functional\App\OpenApi\RelatedPaginator\RelatedPaginatorTestKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * A to-many relation that declares no paginator is served with the related
 * resource's paginator (`books`: 2 per page, at most 5), so the document has to state
 * those numbers on the related and relationship endpoints, not the server default's.
 */
final class OpenApiRelatedPaginatorConformanceTest extends JsonApiFunctionalTestCase
{
    use SchemaConformanceTrait;

    protected static function getKernelClass(): string
    {
        return RelatedPaginatorTestKernel::class;
    }

    #[Test]
    #[Group('spec:openapi')]
    public function theRelatedEndpointDocumentsTheRelatedResourcesPaginator(): void
    {
        $response = $this->handle('/shelves/1/books');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertCount(2, $this->dataOf($response));

        // A monomorphic related to-many is documented as the related type's collection.
        $this->assertResponseMatchesGeneratedSchema($response, 'books', SchemaDocumentKind::Collection);
        self::assertSame(['default' => 2, 'maximum' => 5], $this->pageSize('/shelves/{id}/books'));
    }

    #[Test]
    #[Group('spec:openapi')]
    public function theRelationshipEndpointDocumentsTheRelatedResourcesPaginator(): void
    {
        $response = $this->handle('/shelves/1/relationships/books');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertCount(2, $this->dataOf($response));

        $this->assertResponseMatchesGeneratedSchema($response, 'shelves', SchemaDocumentKind::Relationship, 'books');
        self::assertSame(['default' => 2, 'maximum' => 5], $this->pageSize('/shelves/{id}/relationships/books'));
    }

    /**
     * @return list<mixed>
     */
    private function dataOf(Response $response): array
    {
        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);

        return \array_values($data);
    }

    /**
     * The `default` and `maximum` the document puts on the `page[size]` member of a
     * `GET` operation's `page` parameter.
     *
     * @return array{default: mixed, maximum: mixed}
     */
    private function pageSize(string $path): array
    {
        $document = \json_decode(\json_encode($this->generatedDocument(null), \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR);
        \assert(\is_array($document) && \is_array($document['paths'] ?? null));
        $operation = $document['paths'][$path] ?? null;
        \assert(\is_array($operation) && \is_array($operation['get'] ?? null));

        /** @var list<array{name: string, schema?: array{properties?: array{size?: array<string, mixed>}}}> $parameters */
        $parameters = $operation['get']['parameters'] ?? [];
        foreach ($parameters as $parameter) {
            if ($parameter['name'] === 'page') {
                $size = $parameter['schema']['properties']['size'] ?? [];

                return ['default' => $size['default'] ?? null, 'maximum' => $size['maximum'] ?? null];
            }
        }

        self::fail(\sprintf('GET %s documents no page parameter.', $path));
    }
}
