<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Resource;

use haddowg\JsonApi\Pagination\CursorPaginator;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;

/**
 * The shared `cursorGroups` declaration both functional kernels serve for the cursor
 * (keyset) INCLUDE conformance suite: its to-many `widgets` relation declares its OWN
 * {@see CursorPaginator} (default size 2) via {@see HasMany::paginate()}, so a
 * `?include=widgets` on `/cursorGroups` windows the whole page of groups to page 1 of the
 * keyset.
 *
 * `cursorGroups` is the INVERSE-FK (Doctrine `OneToMany`) complement of the owning-side
 * ManyToMany {@see BaseCursorShelfResource}: over Doctrine the related widget carries the
 * owning `group_id` FK, so the cursor include collapses to the inverse-FK single-window
 * shape (partition by the owning FK, no join table); over the in-memory kernel the members
 * read off the parent's `widgets` property through the same keyset execution. The related
 * vocabulary (sortable `category`/`priority`/`releasedAt`/`id`) comes from the related
 * {@see BaseCursorWidgetResource}, so the nullable `priority` keyset is reachable on the
 * include. The concrete subclasses only choose the data layer.
 */
abstract class BaseCursorGroupResource extends AbstractResource
{
    public static string $type = 'cursorGroups';

    public function fields(): array
    {
        return [
            Id::make(),
            HasMany::make('widgets', 'cursorWidgets')
                ->paginate(CursorPaginator::make()->withDefaultSize(2)),
        ];
    }
}
