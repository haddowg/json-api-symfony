<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\OpenApi\Metadata;

use haddowg\JsonApi\OpenApi\Metadata\PaginatorKind;
use haddowg\JsonApi\Pagination\CursorPaginator;
use haddowg\JsonApi\Pagination\FixedPagePaginator;
use haddowg\JsonApi\Pagination\OffsetPaginator;
use haddowg\JsonApi\Pagination\PagePaginator;
use haddowg\JsonApi\Pagination\PaginatorInterface;

/**
 * Discriminates a resolved core {@see PaginatorInterface} to the OpenAPI
 * {@see PaginatorKind} the projector reads to enumerate the right `page[…]`
 * parameters (design §4 / the {@see PaginatorKind} docblock).
 *
 * Core's {@see PaginatorInterface} carries no self-description — the contract pushes
 * this `instanceof` discrimination to the metadata source on purpose, which is why
 * the enum exists. The built-in strategies map by class:
 *  - {@see PagePaginator} / {@see FixedPagePaginator} → {@see PaginatorKind::Page};
 *  - {@see OffsetPaginator} → {@see PaginatorKind::Offset};
 *  - {@see CursorPaginator} → {@see PaginatorKind::Cursor};
 *  - `null` → {@see PaginatorKind::None} (unpaginated).
 *
 * A custom application paginator that matches none of the built-ins is treated as
 * the count-based {@see PaginatorKind::Page} — the JSON:API-conventional default
 * (`page[number]` + `page[size]`) — rather than throwing, so a documented surface
 * never fails to generate over an unrecognized strategy. (A future seam could let a
 * custom paginator self-describe its kind; until then Page is the safe projection.)
 */
final class PaginatorKindResolver
{
    public function resolve(?PaginatorInterface $paginator): PaginatorKind
    {
        return match (true) {
            $paginator === null => PaginatorKind::None,
            $paginator instanceof OffsetPaginator => PaginatorKind::Offset,
            $paginator instanceof CursorPaginator => PaginatorKind::Cursor,
            $paginator instanceof PagePaginator, $paginator instanceof FixedPagePaginator => PaginatorKind::Page,
            default => PaginatorKind::Page,
        };
    }
}
