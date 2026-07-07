<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\Responses\ResponseDeclarationTestKernel;
use PHPUnit\Framework\Attributes\Test;

/**
 * The read-path async-completion witness: a {@see \haddowg\JsonApiBundle\Tests\Functional\App\Responses\WidgetResource}
 * implementing {@see \haddowg\JsonApi\Resource\ResolvesCompletionRedirect} answers a
 * fetch-one with `303 See Other` (to the produced resource) when it resolves a
 * completion location for the loaded entity, and a normal `200` otherwise — the
 * read-side twin of the async-write `AcceptedForProcessing` seam.
 */
final class ResponseDeclarationTest extends JsonApiFunctionalTestCase
{
    protected static function getKernelClass(): string
    {
        return ResponseDeclarationTestKernel::class;
    }

    #[Test]
    public function aResolvedCompletionLocationRedirectsAFetchOneWith303(): void
    {
        $response = $this->handle('/widgets/1');

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('https://example.test/widgets/done', $response->headers->get('Location'));
    }

    #[Test]
    public function aFetchOneWithoutACompletionLocationRendersNormally(): void
    {
        $response = $this->handle('/widgets/2');

        self::assertSame(200, $response->getStatusCode());
        $body = \json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        $data = $body['data'] ?? null;
        self::assertIsArray($data);
        self::assertSame('widgets', $data['type'] ?? null);
        self::assertSame('2', $data['id'] ?? null);
    }

    #[Test]
    public function theDeclaredResponseSetsAreProjectedIntoTheOpenApiDocument(): void
    {
        $response = $this->handle('/docs.json');

        self::assertSame(200, $response->getStatusCode());
        $doc = \json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        // POST /widgets advertises 201 (created) + 202 (async accept → jobs document).
        $create = $this->operationResponses($doc, '/widgets', 'post');
        self::assertArrayHasKey('201', $create);
        self::assertArrayHasKey('202', $create);
        $accepted = $create['202'];
        self::assertIsArray($accepted);
        $acceptedHeaders = $accepted['headers'] ?? null;
        self::assertIsArray($acceptedHeaders);
        self::assertArrayHasKey('Content-Location', $acceptedHeaders);
        self::assertArrayHasKey('Retry-After', $acceptedHeaders);

        // GET /widgets/{id} advertises 200 + 303 (completion redirect, Location, no body).
        $fetchOne = $this->operationResponses($doc, '/widgets/{id}', 'get');
        self::assertArrayHasKey('200', $fetchOne);
        self::assertArrayHasKey('303', $fetchOne);
        $redirect = $fetchOne['303'];
        self::assertIsArray($redirect);
        $redirectHeaders = $redirect['headers'] ?? null;
        self::assertIsArray($redirectHeaders);
        self::assertArrayHasKey('Location', $redirectHeaders);
        self::assertArrayNotHasKey('content', $redirect);

        // PATCH advertises 200 + 204; DELETE advertises 204 + 200 (meta-only).
        $update = $this->operationResponses($doc, '/widgets/{id}', 'patch');
        self::assertArrayHasKey('200', $update);
        self::assertArrayHasKey('204', $update);

        $delete = $this->operationResponses($doc, '/widgets/{id}', 'delete');
        self::assertArrayHasKey('204', $delete);
        self::assertArrayHasKey('200', $delete);
    }

    #[Test]
    public function anAsyncActionAdvertises202And303InTheOpenApiDocument(): void
    {
        $response = $this->handle('/docs.json');

        self::assertSame(200, $response->getStatusCode());
        $doc = \json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        // GET /widgets/-actions/poll declares responds: [Accepted('jobs'), SeeOther()] →
        // the document advertises a 202 (jobs document + Content-Location + Retry-After)
        // and a 303 (Location, no body).
        $poll = $this->operationResponses($doc, '/widgets/-actions/poll', 'get');
        self::assertArrayHasKey('202', $poll);
        self::assertArrayHasKey('303', $poll);

        $accepted = $poll['202'];
        self::assertIsArray($accepted);
        $acceptedHeaders = $accepted['headers'] ?? null;
        self::assertIsArray($acceptedHeaders);
        self::assertArrayHasKey('Content-Location', $acceptedHeaders);
        self::assertArrayHasKey('Retry-After', $acceptedHeaders);

        $redirect = $poll['303'];
        self::assertIsArray($redirect);
        $redirectHeaders = $redirect['headers'] ?? null;
        self::assertIsArray($redirectHeaders);
        self::assertArrayHasKey('Location', $redirectHeaders);
    }

    /**
     * Navigates to `paths.<path>.<method>.responses` in the decoded OpenAPI document,
     * asserting each level is an array.
     *
     * @return array<mixed, mixed>
     */
    private function operationResponses(mixed $doc, string $path, string $method): array
    {
        self::assertIsArray($doc);
        $paths = $doc['paths'] ?? null;
        self::assertIsArray($paths);
        $item = $paths[$path] ?? null;
        self::assertIsArray($item);
        $operation = $item[$method] ?? null;
        self::assertIsArray($operation);
        $responses = $operation['responses'] ?? null;
        self::assertIsArray($responses);

        return $responses;
    }
}
