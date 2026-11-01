<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Resource;

use haddowg\JsonApi\Pagination\PagePaginator;
use haddowg\JsonApi\Pagination\PaginatorInterface;

/**
 * The `articles` declaration for the after-fetch-collection paginator suite: the
 * {@see BaseArticleResource} vocabulary (its singular `exactTitle` filter
 * included), with a {@see pagination()} each test swaps through {@see $paginator},
 * so one type witnesses offset, cursor, multi and fetch-all collections.
 *
 * Not `final` so the Doctrine variant inherits the same switch.
 */
class PaginatorProbeArticleResource extends BaseArticleResource
{
    public static ?PaginatorInterface $paginator = null;

    public static function reset(): void
    {
        self::$paginator = PagePaginator::make();
    }

    public function pagination(?PaginatorInterface $serverDefault): ?PaginatorInterface
    {
        return self::$paginator;
    }
}
