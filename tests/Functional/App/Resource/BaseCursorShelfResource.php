<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Resource;

use haddowg\JsonApi\Pagination\CursorPaginator;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\BelongsToManyBuilder;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;

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
 *
 * It also declares a `pivotWidgets` {@see BelongsToMany} carrying a `slot`
 * pivot field under the SAME cursor paginator — the pivot-related cursor
 * conformance surface (bundle ADR 0114). Over the Doctrine kernel the relation
 * pins its association entity (see the Doctrine subclass's
 * {@see pivotWidgets()} override), so the fetch runs the pivot keyset
 * push-down and each member renders `meta.pivot`; over the in-memory kernel the
 * provider is not pivot-aware (the documented boundary), so the members read
 * off the parent's `widgets` property through the PLAIN keyset execution — the
 * page walks are identical, only the pivot vocabulary/meta differ.
 */
abstract class BaseCursorShelfResource extends AbstractResource
{
    public static string $type = 'cursorShelves';

    public function fields(): array
    {
        return [
            Id::make()->build(),
            HasMany::make('widgets', 'cursorWidgets')
                ->paginate(CursorPaginator::make()->withDefaultSize(2)),
            $this->pivotWidgets(),
        ];
    }

    /**
     * The cursor-paginated pivot relation, overridable so the Doctrine subclass
     * can pin its association entity via `through()` while the shared shape (the
     * `slot` pivot field, the paginator, the `widgets` backing property) stays
     * declared once.
     */
    protected function pivotWidgets(): BelongsToManyBuilder
    {
        return BelongsToMany::make('pivotWidgets', 'cursorWidgets')
            ->storedAs('widgets')
            ->fields(Integer::make('slot')->build())
            ->paginate(CursorPaginator::make()->withDefaultSize(2));
    }
}
