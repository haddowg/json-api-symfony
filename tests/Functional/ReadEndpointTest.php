<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\JsonApiTestKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The Phase-0 acceptance test: it boots {@see JsonApiTestKernel} and issues
 * `GET /articles/1`, `GET /articles`, and `GET /articles/999` through the kernel,
 * asserting each response is a spec-compliant JSON:API document. This is the
 * end-to-end witness that the listeners, the Server factory, `Server::dispatch()`,
 * the render seam, and the PSR-7 <-> HttpFoundation bridge work together.
 */
final class ReadEndpointTest extends JsonApiFunctionalTestCase
{
    protected static function getKernelClass(): string
    {
        return JsonApiTestKernel::class;
    }

    #[Test]
    #[Group('spec:fetching-resources')]
    public function fetchingASingleResourceRendersASpecCompliantDocument(): void
    {
        $response = $this->handle('/articles/1');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));

        $document = $this->decode($response);

        $jsonapi = $document['jsonapi'] ?? null;
        self::assertIsArray($jsonapi);
        self::assertSame('1.1', $jsonapi['version'] ?? null);

        $data = $document['data'] ?? null;
        self::assertIsArray($data);
        self::assertSame('articles', $data['type'] ?? null);
        self::assertSame('1', $data['id'] ?? null);

        $attributes = $data['attributes'] ?? null;
        self::assertIsArray($attributes);
        self::assertSame('JSON:API in PHP', $attributes['title'] ?? null);
        self::assertSame('A worked example.', $attributes['body'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching-resources')]
    public function fetchingACollectionRendersAnArrayOfResources(): void
    {
        $response = $this->handle('/articles');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));

        $document = $this->decode($response);

        $data = $document['data'] ?? null;
        self::assertIsArray($data);
        self::assertCount(5, $data);

        $first = $data[0] ?? null;
        self::assertIsArray($first);
        self::assertSame('articles', $first['type'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching-resources')]
    public function aMissingResourceRendersA404ErrorDocument(): void
    {
        $response = $this->handle('/articles/999');

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));

        $document = $this->decode($response);

        $errors = $document['errors'] ?? null;
        self::assertIsArray($errors);
        self::assertNotEmpty($errors);

        $firstError = $errors[0] ?? null;
        self::assertIsArray($firstError);
        self::assertSame('404', $firstError['status'] ?? null);
    }
}
