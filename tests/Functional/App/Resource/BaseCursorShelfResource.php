<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Resource;

use haddowg\JsonApi\Pagination\CursorPaginator;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;

/**
 * The shared `cursorShelves` declaration both functional kernels serve for the
 * RELATED-collection cursor (keyset) conformance suite: its to-many `widgets`
 * relation declares its OWN {@see CursorPaginator} (default size 2) via
 * {@see HasMany::paginate()}, so `GET /cursorShelves/{id}/widgets` resolves a
 * keyset window and the providers run the same keyset execution as the primary
 * `/cursorWidgets` collection — scoped to the parent (bundle ADR 0063).
 *
 * The related vocabulary (sortable `category`/`priority`/`releasedAt`/`id`)
 * comes from the related {@see BaseCursorWidgetResource}, so every keyset shape
 * the primary conformance walks is reachable on the related endpoint too. The
 * concrete subclasses only choose the data layer.
 */
abstract class BaseCursorShelfResource extends AbstractResource
{
    public static string $type = 'cursorShelves';

    public function fields(): array
    {
        return [
            Id::make(),
            HasMany::make('widgets', 'cursorWidgets')
                ->paginate(CursorPaginator::make()->withDefaultSize(2)),
        ];
    }
}
