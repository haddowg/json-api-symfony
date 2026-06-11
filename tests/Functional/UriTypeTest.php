<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\UriTypeTestKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Routing\RouterInterface;

/**
 * The uriType witness (ADR 0022): the `book` resource declares `$uriType = 'books'`,
 * so its routes, relationship convention links and the create Location header use
 * the `books` segment, while the rendered document `type` member stays `book`.
 * uriType is a routing/rendering concern, identical on every provider, so it is
 * witnessed on the in-memory kernel only.
 */
final class UriTypeTest extends JsonApiFunctionalTestCase
{
    protected static function getKernelClass(): string
    {
        return UriTypeTestKernel::class;
    }

    #[Test]
    #[Group('spec:fetching')]
    public function routesAreEmittedAtTheUriSegmentNotTheType(): void
    {
        self::assertSame(200, $this->handle('/books')->getStatusCode());

        // The route paths use the URI segment; the JSON:API type is not itself a
        // path (asserted against the route collection rather than an unrouted
        // request, which the framework would log as an uncaught 404).
        $router = static::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);

        $paths = [];
        foreach ($router->getRouteCollection() as $route) {
            $paths[] = $route->getPath();
        }

        self::assertContains('/books', $paths);
        self::assertNotContains('/book', $paths);
    }

    #[Test]
    #[Group('spec:document-resource-object-relationships')]
    public function theDocumentTypeStaysTheJsonApiTypeWhileLinksUseTheSegment(): void
    {
        $response = $this->handle('/books/b1');
        self::assertSame(200, $response->getStatusCode());

        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);

        // The `type` member is the JSON:API type, not the URI segment.
        self::assertSame('book', $data['type'] ?? null);

        // The relationship convention links use the URI segment (`books`).
        $relationships = $data['relationships'] ?? null;
        self::assertIsArray($relationships);
        $related = $relationships['related'] ?? null;
        self::assertIsArray($related);
        $links = $related['links'] ?? null;
        self::assertIsArray($links);
        self::assertSame('https://example.test/books/b1/relationships/related', $links['self'] ?? null);
        self::assertSame('https://example.test/books/b1/related', $links['related'] ?? null);
    }

    #[Test]
    #[Group('spec:crud')]
    public function createReturnsALocationAtTheUriSegment(): void
    {
        $response = $this->handle('/books', 'POST', [
            'data' => [
                'type' => 'book',
                'attributes' => ['title' => 'A fresh title'],
            ],
        ]);

        self::assertSame(201, $response->getStatusCode());

        $data = $this->decode($response)['data'] ?? null;
        self::assertIsArray($data);
        self::assertSame('book', $data['type'] ?? null);

        $id = $data['id'] ?? null;
        self::assertIsString($id);
        self::assertSame('https://example.test/books/' . $id, $response->headers->get('Location'));
    }

    #[Test]
    #[Group('spec:fetching-relationships')]
    public function relationshipEndpointsAreRoutedAtTheUriSegment(): void
    {
        self::assertSame(200, $this->handle('/books/b1/relationships/related')->getStatusCode());
        self::assertSame(200, $this->handle('/books/b1/related')->getStatusCode());
    }
}
