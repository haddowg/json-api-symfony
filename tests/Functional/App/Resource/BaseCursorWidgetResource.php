<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Resource;

use haddowg\JsonApi\Pagination\CursorPaginator;
use haddowg\JsonApi\Pagination\MultiPaginator;
use haddowg\JsonApi\Pagination\PagePaginator;
use haddowg\JsonApi\Pagination\PaginatorInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * The shared `cursorWidgets` declaration both functional kernels serve for the
 * cursor (keyset) conformance suite. Its `pagination()` returns a
 * {@see MultiPaginator} offering a page-number strategy alongside the cursor
 * (keyset) strategy, defaulting to cursor — so the same endpoint witnesses both
 * client-selectable strategy selection AND the keyset push-down (bundle ADR 0063).
 * Absent a discriminator (or with only the shared `page[size]` key) it resolves to
 * the cursor default, keeping the keyset suite unchanged; `page[kind]=page` (or the
 * page-unique `page[number]`) selects the count-based strategy instead.
 *
 * `category`, `priority` and `releasedAt` are all sortable: `category` carries
 * ties (so the appended PK tiebreak is exercised), `priority` is a NULLABLE int
 * (the null-branch ground truth), and `releasedAt` is a NULLABLE datetime (the
 * date-keyed + typed-binding case). The concrete subclasses only choose the data
 * layer.
 */
abstract class BaseCursorWidgetResource extends AbstractResource
{
    public static string $type = 'cursorWidgets';

    public function fields(): array
    {
        return [
            // `id` is sortable, so `?sort=…,id` is a valid explicit tiebreak (and
            // the keyset's dedupe path — the client already sorting by the PK key —
            // is exercised). When `?sort` omits it, the keyset appends it anyway.
            Id::make()->sortable(),
            Str::make('category')->sortable(),
            Integer::make('priority')->nullable()->sortable(),
            DateTime::make('releasedAt')->nullable()->sortable(),
        ];
    }

    public function pagination(?PaginatorInterface $serverDefault): ?PaginatorInterface
    {
        return MultiPaginator::make(
            PagePaginator::make()->withDefaultPerPage(2),
            CursorPaginator::make()->withDefaultSize(2),
        )->default('cursor');
    }
}
