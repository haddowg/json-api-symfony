<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\OpenApi\Metadata;

use haddowg\JsonApi\OpenApi\Metadata\PaginatorKind;
use haddowg\JsonApi\Pagination\CursorPaginator;
use haddowg\JsonApi\Pagination\DescribesPaginatorKindInterface;
use haddowg\JsonApi\Pagination\FixedPagePaginator;
use haddowg\JsonApi\Pagination\OffsetPaginator;
use haddowg\JsonApi\Pagination\PagePaginator;
use haddowg\JsonApi\Pagination\PaginatorInterface;
use haddowg\JsonApiBundle\OpenApi\Metadata\PaginatorKindResolver;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Characterizes the {@see PaginatorKindResolver} (design §4): it discriminates a
 * resolved core paginator to the OpenAPI {@see PaginatorKind} the projector reads —
 * trusting a paginator that self-describes via
 * {@see \haddowg\JsonApi\Pagination\DescribesPaginatorKindInterface}, else the built-in
 * `instanceof` map, else the Page default for an unrecognized strategy.
 */
#[Group('spec:openapi')]
final class PaginatorKindResolverTest extends TestCase
{
    #[Test]
    public function nullResolvesToNone(): void
    {
        self::assertSame(PaginatorKind::None, (new PaginatorKindResolver())->resolve(null));
    }

    #[Test]
    public function aPagePaginatorResolvesToPage(): void
    {
        self::assertSame(PaginatorKind::Page, (new PaginatorKindResolver())->resolve(PagePaginator::make()));
    }

    #[Test]
    public function aFixedPagePaginatorResolvesToPage(): void
    {
        self::assertSame(PaginatorKind::Page, (new PaginatorKindResolver())->resolve(FixedPagePaginator::make()));
    }

    #[Test]
    public function anOffsetPaginatorResolvesToOffset(): void
    {
        self::assertSame(PaginatorKind::Offset, (new PaginatorKindResolver())->resolve(OffsetPaginator::make()));
    }

    #[Test]
    public function aCursorPaginatorResolvesToCursor(): void
    {
        self::assertSame(PaginatorKind::Cursor, (new PaginatorKindResolver())->resolve(CursorPaginator::make()));
    }

    #[Test]
    public function anUnrecognizedPaginatorDefaultsToPage(): void
    {
        $custom = new class implements PaginatorInterface {
            public function window(\haddowg\JsonApi\Request\JsonApiRequestInterface $request): \haddowg\JsonApi\Pagination\WindowInterface
            {
                throw new \LogicException('never called');
            }

            public function paginate(\haddowg\JsonApi\Request\JsonApiRequestInterface $request, iterable $items, int $totalItems): \haddowg\JsonApi\Pagination\PageInterface
            {
                throw new \LogicException('never called');
            }

            public function paginateWithoutCount(\haddowg\JsonApi\Request\JsonApiRequestInterface $request, iterable $items, bool $hasMore): \haddowg\JsonApi\Pagination\PageInterface
            {
                throw new \LogicException('never called');
            }

            public function wantsCount(): bool
            {
                return false;
            }
        };

        self::assertSame(PaginatorKind::Page, (new PaginatorKindResolver())->resolve($custom));
    }

    #[Test]
    public function aSelfDescribingPaginatorReportsItsOwnKind(): void
    {
        // A custom paginator matching none of the built-in classes but declaring its
        // kind via the optional interface: the resolver trusts the self-description
        // instead of the Page default. Deleting the interface-first arm fails this.
        $custom = new class implements PaginatorInterface, DescribesPaginatorKindInterface {
            public function paginatorKind(): PaginatorKind
            {
                return PaginatorKind::Cursor;
            }

            public function window(\haddowg\JsonApi\Request\JsonApiRequestInterface $request): \haddowg\JsonApi\Pagination\WindowInterface
            {
                throw new \LogicException('never called');
            }

            public function paginate(\haddowg\JsonApi\Request\JsonApiRequestInterface $request, iterable $items, int $totalItems): \haddowg\JsonApi\Pagination\PageInterface
            {
                throw new \LogicException('never called');
            }

            public function paginateWithoutCount(\haddowg\JsonApi\Request\JsonApiRequestInterface $request, iterable $items, bool $hasMore): \haddowg\JsonApi\Pagination\PageInterface
            {
                throw new \LogicException('never called');
            }

            public function wantsCount(): bool
            {
                return false;
            }
        };

        self::assertSame(PaginatorKind::Cursor, (new PaginatorKindResolver())->resolve($custom));
    }
}
