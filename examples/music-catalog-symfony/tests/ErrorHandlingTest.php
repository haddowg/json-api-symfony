<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * The error-handling acceptance suite (backs `errors.md`): every failure on a
 * JSON:API route is rendered by the route-scoped `ExceptionListener` as a
 * spec-compliant error document — the `application/vnd.api+json` media type, an
 * `errors` array, a string `status`, and a stable error `code` — across the
 * status codes the example app can produce end to end (`400`, `404`, `422`).
 *
 * The kernel boots with `kernel.debug = false` (the functional base case), so this
 * also witnesses the **debug gating**: no error document leaks `{exception, file,
 * line, trace}` meta or a debug `detail` beyond the spec's stable fields. The
 * firewall-driven `401`/`403` arm and the generic `500` arm are exercised in the
 * bundle's own `tests/Functional` suite (the example app ships no firewall, and a
 * live `500` would emit framework error-log output the strict test harness flags);
 * the route-scoped rendering contract they share is witnessed here on the `4xx`
 * arms.
 */
#[Group('spec:errors')]
final class ErrorHandlingTest extends MusicCatalogKernelTestCase
{
    #[Test]
    public function aMissingResourceRendersARouteScoped404Document(): void
    {
        // The show route for `albums` matches, so the request reaches the handler and
        // the provider's null fetch becomes a JSON:API 404 — not a bare HTML 404.
        $error = $this->errorDocument('/albums/999', 404);

        self::assertSame('404', $error['status'] ?? null);
        self::assertSame('RESOURCE_NOT_FOUND', $error['code'] ?? null);
    }

    #[Test]
    public function anUnknownRelationshipRendersA404Document(): void
    {
        $error = $this->errorDocument('/tracks/1/bogus', 404);

        self::assertSame('404', $error['status'] ?? null);
        self::assertSame('RELATIONSHIP_NOT_EXISTS', $error['code'] ?? null);
    }

    #[Test]
    public function anUnknownFilterRendersA400Document(): void
    {
        $error = $this->errorDocument('/tracks?filter[nope]=x', 400);

        self::assertSame('400', $error['status'] ?? null);
        self::assertSame('FILTERING_UNRECOGNIZED', $error['code'] ?? null);
        self::assertSame(['parameter' => 'filter[nope]'], $error['source'] ?? null);
    }

    #[Test]
    public function anUnknownSortRendersA400Document(): void
    {
        $error = $this->errorDocument('/tracks?sort=nope', 400);

        self::assertSame('400', $error['status'] ?? null);
        self::assertSame('SORTING_UNRECOGNIZED', $error['code'] ?? null);
    }

    #[Test]
    public function aMalformedJsonBodyRendersA400Document(): void
    {
        // A POST whose body is not valid JSON is rejected before hydration with a
        // route-scoped 400 (content negotiation owns this, not the handler).
        $response = $this->postRaw('/playlists', '{not valid json');

        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));

        $error = $this->firstError($response);
        self::assertSame('400', $error['status'] ?? null);
        self::assertSame('REQUEST_BODY_INVALID_JSON', $error['code'] ?? null);
    }

    #[Test]
    #[Group('spec:crud')]
    public function aValidationFailureRendersA422Document(): void
    {
        $response = $this->handle('/tracks', 'POST', [
            'data' => ['type' => 'tracks', 'attributes' => [
                'trackNumber' => 1, 'durationSeconds' => 10, 'genres' => ['rock'],
            ]],
        ]);

        $error = $this->firstError($response, 422);
        self::assertSame('422', $error['status'] ?? null);
        self::assertSame('VALIDATION_FAILED', $error['code'] ?? null);

        $source = $error['source'] ?? null;
        self::assertIsArray($source);
        self::assertSame('/data/attributes/title', $source['pointer'] ?? null);
    }

    #[Test]
    public function errorDocumentsLeakNoDebugMetaWithDebugOff(): void
    {
        // With kernel.debug off, the ExceptionListener redacts internal detail: no
        // error carries an `exception`/`file`/`line`/`trace` meta key.
        foreach (['/albums/999', '/tracks/1/bogus', '/tracks?filter[nope]=x'] as $path) {
            $response = $this->handle($path);
            self::assertGreaterThanOrEqual(400, $response->getStatusCode());

            $document = $this->decode($response);
            $errors = $document['errors'] ?? null;
            self::assertIsArray($errors);

            foreach ($errors as $error) {
                self::assertIsArray($error);
                $meta = $error['meta'] ?? null;
                if (\is_array($meta)) {
                    self::assertArrayNotHasKey('exception', $meta);
                    self::assertArrayNotHasKey('file', $meta);
                    self::assertArrayNotHasKey('line', $meta);
                    self::assertArrayNotHasKey('trace', $meta);
                }
            }

            // The top-level document carries no debug meta either.
            $documentMeta = $document['meta'] ?? null;
            if (\is_array($documentMeta)) {
                self::assertArrayNotHasKey('exception', $documentMeta);
                self::assertArrayNotHasKey('trace', $documentMeta);
            }
        }
    }

    /**
     * Issues a POST with a raw (here deliberately malformed) JSON:API body — the
     * base `handle()` json-encodes an array body, so a raw string is sent directly.
     */
    private function postRaw(string $path, string $content): Response
    {
        $kernel = static::$kernel;
        self::assertNotNull($kernel);

        $request = Request::create($path, 'POST', server: [
            'HTTP_ACCEPT' => 'application/vnd.api+json',
            'CONTENT_TYPE' => 'application/vnd.api+json',
        ], content: $content);

        return $kernel->handle($request, HttpKernelInterface::MAIN_REQUEST, true);
    }

    /**
     * Fetches `$path`, asserts the status and the JSON:API media type, and returns
     * the first error object.
     *
     * @return array<string, mixed>
     */
    private function errorDocument(string $path, int $status): array
    {
        $response = $this->handle($path);

        self::assertSame($status, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));

        return $this->firstError($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function firstError(Response $response, ?int $status = null): array
    {
        if ($status !== null) {
            self::assertSame($status, $response->getStatusCode(), (string) $response->getContent());
        }

        $errors = $this->decode($response)['errors'] ?? null;
        self::assertIsArray($errors);
        self::assertNotEmpty($errors);

        $first = $errors[0] ?? null;
        self::assertIsArray($first);

        /** @var array<string, mixed> $first */
        return $first;
    }
}
