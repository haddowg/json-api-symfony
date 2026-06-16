<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\HandlerDecorationTestKernel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The handler-override witness (ADR 0028): an application overrides handling for a
 * specific type/operation by **decorating** the single generic
 * {@see \haddowg\JsonApiBundle\Operation\CrudOperationHandler}. The `ServerFactory`
 * resolves the handler by service id, so the Symfony decoration is transparently
 * picked up — the server dispatches to the decorator, which wraps the generic
 * engine as its inner.
 *
 * The intercepted case (a single-resource `GET /book/{id}` fetch) carries the
 * decorator's `meta.decorated` marker on the engine's own document; a delegated
 * case (the `GET /book` collection fetch, passed through unchanged) carries the
 * normal generic-engine document **without** the marker — proving interception and
 * delegation compose through one decorator.
 */
final class HandlerDecorationTest extends JsonApiFunctionalTestCase
{
    protected static function getKernelClass(): string
    {
        return HandlerDecorationTestKernel::class;
    }

    #[Test]
    #[Group('spec:fetching')]
    public function theInterceptedSingleResourceFetchCarriesTheDecoratorMarker(): void
    {
        $response = $this->handle('/books/1');
        self::assertSame(200, $response->getStatusCode());

        $document = $this->decode($response);

        // The decorator delegated to the inner engine for the real document...
        $data = $document['data'] ?? null;
        self::assertIsArray($data);
        self::assertSame('book', $data['type'] ?? null);
        self::assertSame('1', $data['id'] ?? null);

        // ...then enriched it with the distinguishing marker.
        $meta = $document['meta'] ?? null;
        self::assertIsArray($meta);
        self::assertTrue($meta['decorated'] ?? null);
    }

    #[Test]
    #[Group('spec:fetching')]
    public function theDelegatedCollectionFetchIsTheUnmarkedGenericResponse(): void
    {
        $response = $this->handle('/books');
        self::assertSame(200, $response->getStatusCode());

        $document = $this->decode($response);

        // The generic engine still runs through the decorator: a real collection.
        $data = $document['data'] ?? null;
        self::assertIsArray($data);
        self::assertCount(1, $data);
        $first = $data[0] ?? null;
        self::assertIsArray($first);
        self::assertSame('book', $first['type'] ?? null);
        self::assertSame('1', $first['id'] ?? null);

        // The collection fetch is delegated unchanged, so it carries no marker.
        $meta = $document['meta'] ?? null;
        if (\is_array($meta)) {
            self::assertArrayNotHasKey('decorated', $meta);
        }
    }
}
