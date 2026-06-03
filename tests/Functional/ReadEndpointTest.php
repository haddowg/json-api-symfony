<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\JsonApiTestKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * The Phase-0 acceptance test: it boots {@see JsonApiTestKernel} and issues
 * `GET /articles/1`, `GET /articles`, and `GET /articles/999` through the kernel,
 * asserting each response is a spec-compliant JSON:API document. This is the
 * end-to-end witness that the listeners, the Server factory, `Server::dispatch()`,
 * the render seam, and the PSR-7 <-> HttpFoundation bridge work together.
 */
final class ReadEndpointTest extends TestCase
{
    private JsonApiTestKernel $kernel;

    private mixed $errorHandler = null;

    private mixed $exceptionHandler = null;

    protected function setUp(): void
    {
        // Snapshot the active error/exception handlers: booting the kernel
        // installs Symfony's, and PHPUnit's strict mode flags any not restored.
        $this->errorHandler = \set_error_handler(null);
        \restore_error_handler();
        $this->exceptionHandler = \set_exception_handler(null);
        \restore_exception_handler();

        $this->kernel = new JsonApiTestKernel('test', false);
        $this->kernel->boot();
    }

    protected function tearDown(): void
    {
        $this->kernel->shutdown();

        $this->restoreHandlers();
    }

    /**
     * Pops every error/exception handler the kernel pushed, back to the snapshot
     * taken in setUp, so the global handler stack is balanced.
     */
    private function restoreHandlers(): void
    {
        while (true) {
            $current = \set_error_handler(static fn(): bool => false);
            \restore_error_handler();
            if ($current === $this->errorHandler) {
                break;
            }
            \restore_error_handler();
        }

        while (true) {
            $current = \set_exception_handler(null);
            \restore_exception_handler();
            if ($current === $this->exceptionHandler) {
                break;
            }
            \restore_exception_handler();
        }
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
        self::assertCount(2, $data);

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

    private function handle(string $path): Response
    {
        $request = Request::create($path, 'GET', server: [
            'HTTP_ACCEPT' => 'application/vnd.api+json',
        ]);

        $response = $this->kernel->handle($request, HttpKernelInterface::MAIN_REQUEST, false);

        // The kernel installs Symfony's error/exception handlers while handling;
        // pop them back to the snapshot so PHPUnit's strict-handler check sees a
        // balanced stack at the end of the test.
        $this->restoreHandlers();

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        $content = (string) $response->getContent();
        $decoded = \json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
