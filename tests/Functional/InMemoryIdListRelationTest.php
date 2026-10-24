<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApiBundle\Tests\Functional\App\IdListRelation\IdListRelationTestKernel;
use haddowg\JsonApiBundle\Tests\Functional\App\IdListRelation\RecordingLogger;
use PHPUnit\Framework\Attributes\Test;

/**
 * The in-memory store renders relations off its models, so a model holding related
 * ids instead of the related objects must fail loudly on every read path rather than
 * render identifiers with an empty `id`. Shelf 1 holds its to-many `books` as ids,
 * shelf 2 its to-one `featured`, and shelf 3 holds objects.
 */
final class InMemoryIdListRelationTest extends JsonApiFunctionalTestCase
{
    protected static function getKernelClass(): string
    {
        return IdListRelationTestKernel::class;
    }

    #[Test]
    public function aToManyHoldingIdsIsRefusedOnEveryReadPath(): void
    {
        foreach (['/shelves/1', '/shelves', '/shelves/1/relationships/books', '/shelves/1/books', '/shelves/1?include=books'] as $path) {
            $this->assertRefused($path, 'books');
        }
    }

    #[Test]
    public function aToOneHoldingAnIdIsRefusedOnEveryReadPath(): void
    {
        foreach (['/shelves/2', '/shelves/2/relationships/featured', '/shelves/2/featured', '/shelves/2?include=featured'] as $path) {
            $this->assertRefused($path, 'featured');
        }
    }

    #[Test]
    public function relatedObjectsRenderOnEveryReadPath(): void
    {
        foreach (['/shelves/3', '/shelves/3/relationships/books', '/shelves/3/books', '/shelves/3?include=books,featured', '/shelves/3/featured', '/shelves/3/relationships/featured'] as $path) {
            $response = $this->handle($path);
            self::assertSame(200, $response->getStatusCode(), $path . ': ' . $response->getContent());
            self::assertStringContainsString('"id":"1"', (string) $response->getContent(), $path);
        }
    }

    private function assertRefused(string $path, string $relation): void
    {
        RecordingLogger::reset();

        $response = $this->handle($path);

        self::assertSame(500, $response->getStatusCode(), $path . ': ' . $response->getContent());
        $exception = RecordingLogger::firstException();
        self::assertInstanceOf(\LogicException::class, $exception, $path);
        self::assertStringContainsString(
            \sprintf('The in-memory "shelves" store holds a string for its "%s" relation', $relation),
            $exception->getMessage(),
            $path,
        );
    }
}
