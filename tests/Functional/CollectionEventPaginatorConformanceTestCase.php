<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional;

use haddowg\JsonApi\Pagination\CursorPaginator;
use haddowg\JsonApi\Pagination\MultiPaginator;
use haddowg\JsonApi\Pagination\OffsetPaginator;
use haddowg\JsonApi\Pagination\PagePaginator;
use haddowg\JsonApi\Pagination\PaginatorInterface;
use haddowg\JsonApiBundle\Event\AfterFetchCollectionEvent;
use haddowg\JsonApiBundle\Tests\Functional\App\Hook\CollectionPaginatorRecorder;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\PaginatorProbeArticleResource;
use PHPUnit\Framework\Attributes\Test;

/**
 * What a subscriber reads from {@see AfterFetchCollectionEvent::$paginator} on
 * `GET /articles`: the request-resolved paginator, or null when no pagination ran.
 * Abstract over the kernel so the same assertions run against the in-memory
 * ({@see InMemoryCollectionEventPaginatorTest}) and Doctrine
 * ({@see DoctrineCollectionEventPaginatorTest}) providers.
 */
abstract class CollectionEventPaginatorConformanceTestCase extends JsonApiFunctionalTestCase
{
    protected function setUp(): void
    {
        PaginatorProbeArticleResource::reset();
        CollectionPaginatorRecorder::$seen = [];

        parent::setUp();
    }

    #[Test]
    public function aMultiPaginatorArrivesAsTheChildTheRequestSelected(): void
    {
        PaginatorProbeArticleResource::$paginator = MultiPaginator::make(
            PagePaginator::make(),
            CursorPaginator::make(),
        )->default('cursor');

        $this->fetch('/articles');
        $this->fetch('/articles?page[kind]=page&page[size]=2');

        self::assertCount(2, CollectionPaginatorRecorder::$seen);
        [$default, $selected] = CollectionPaginatorRecorder::$seen;

        self::assertInstanceOf(CursorPaginator::class, $default);
        self::assertSame('cursor', $default->kind());
        self::assertInstanceOf(PagePaginator::class, $selected);
        self::assertSame('page', $selected->kind());
    }

    #[Test]
    public function aPagePaginatorArrivesAsDeclared(): void
    {
        $paginator = PagePaginator::make();
        PaginatorProbeArticleResource::$paginator = $paginator;

        $this->fetch('/articles?page[number]=1&page[size]=2');

        self::assertSame([$paginator], CollectionPaginatorRecorder::$seen);
    }

    #[Test]
    public function anOffsetPaginatorArrivesAsDeclared(): void
    {
        $paginator = OffsetPaginator::make();
        PaginatorProbeArticleResource::$paginator = $paginator;

        $this->fetch('/articles?page[offset]=1&page[limit]=2');

        self::assertSame([$paginator], CollectionPaginatorRecorder::$seen);
    }

    #[Test]
    public function aCursorPaginatorArrivesAsDeclared(): void
    {
        $paginator = CursorPaginator::make();
        PaginatorProbeArticleResource::$paginator = $paginator;

        $this->fetch('/articles?page[size]=2');

        self::assertSame([$paginator], CollectionPaginatorRecorder::$seen);
    }

    #[Test]
    public function aFetchAllCollectionCarriesNoPaginator(): void
    {
        PaginatorProbeArticleResource::$paginator = null;

        $this->fetch('/articles');

        self::assertSame([null], CollectionPaginatorRecorder::$seen);
    }

    #[Test]
    public function aSingularFilterCollapseCarriesNoPaginator(): void
    {
        // The resource still declares a paginator; the singular filter skips it.
        self::assertInstanceOf(PaginatorInterface::class, PaginatorProbeArticleResource::$paginator);

        $document = $this->fetch('/articles?filter[exactTitle]=Zebra%20patterns');

        $data = $document['data'] ?? null;
        self::assertIsArray($data);
        self::assertSame('4', $data['id'] ?? null);
        self::assertSame([null], CollectionPaginatorRecorder::$seen);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetch(string $path): array
    {
        $response = $this->handle($path);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return $this->decode($response);
    }
}
