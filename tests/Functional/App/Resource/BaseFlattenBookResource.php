<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Resource;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * The shared `books` declaration both flattened-attribute (`on()`) kernels serve
 * (bundle ADR 0085), so the in-memory and Doctrine providers are exercised by
 * IDENTICAL assertions. It declares the orthogonal attribute trio plus the multi-hop
 * eager-load surface:
 *
 *  - `title` — a plain attribute (`model.title`);
 *  - `authorName` — FLATTENED from the hidden to-one `author` relation
 *    (`on('author')`, stored as the author's `name`): read flattens `author.name`,
 *    write mutates the loaded author's `name` in place (require-exists: a null
 *    related author 422s, no auto-instantiate);
 *  - `authorCountry` — FLATTENED over the MULTI-HOP chain `on('author.country')`
 *    (stored as the country's `name`): read flattens `author.country.name` walking two
 *    to-one hops, any intermediate null short-circuits to null;
 *  - `display` — COMPUTED (`computedUsing()`): read-only on create AND update, the
 *    value derived by the closure (no serializeValue() cast);
 *  - `editorName` — FLATTENED from the VISIBLE to-one `editor` relation (`on('editor')`),
 *    the same-body write witness;
 *  - `author` / `country` — HIDDEN to-one relations, so they never render as a
 *    relationship; `author` backs `authorName`/the first hop of `authorCountry`,
 *    `country` (on authors) backs the second hop of `authorCountry`.
 *
 * The eager-load set the bundle materialises is therefore the dedup set of every `on()`
 * chain — `[author, author.country, editor]` — loaded segment by segment (the shared
 * `author` prefix loads ONCE per page, then `country` across those authors), no N+1, and
 * none expanded into `included`.
 */
abstract class BaseFlattenBookResource extends AbstractResource
{
    public static string $type = 'books';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title')->sortable(),
            // Flattened: read/write the author's `name` (storedAs maps the field's
            // backing member to `name` on the related author). on('author') resolves
            // the hidden to-one and honours its column()/storedAs().
            Str::make('authorName')->storedAs('name')->on('author'),
            // Flattened over the MULTI-HOP chain `author.country` (stored as the
            // country's `name`): read flattens `author.country.name`, walking two to-one
            // hops. Any intermediate null short-circuits to null. The eager walk loads
            // each book's author (the shared prefix with `authorName`), then each
            // author's country — O(depth), never per-row.
            Str::make('authorCountry')->storedAs('name')->on('author.country'),
            // Computed: read-only, the value owned by the closure (no cast). Sending
            // it on a write is silently ignored (read-only on create AND update).
            Str::make('display')->computedUsing(
                static function (mixed $model, JsonApiRequestInterface $request, string $name): string {
                    $title = \is_object($model) && \property_exists($model, 'title') ? (string) $model->title : '';

                    return 'Book: ' . $title;
                },
            ),
            // Flattened over a VISIBLE (non-hidden) to-one backing relation: the design
            // permits an `on()` relation to be either hidden or visible. A visible
            // backing relation can carry linkage in a write body, so this is the
            // same-body witness — a write that associates `editor` AND sets `editorName`
            // in one document must flatten onto the editor associated in THAT body
            // (bundle ADR 0085: `on()` attributes hydrate AFTER relationships).
            Str::make('editorName')->storedAs('name')->on('editor'),
            // Hidden backing relation: the idiomatic "internal association" — never a
            // rendered relationship, but eager-loaded for the flattened read. It backs
            // both `authorName` and the first hop of the multi-hop `authorCountry`.
            BelongsTo::make('author', 'authors')->hidden(),
            // The VISIBLE backing relation for the flattened `editorName`: it renders as
            // a relationship and can be associated in a write body, so a same-body write
            // exercises the relationship-before-flatten hydration order.
            BelongsTo::make('editor', 'authors'),
        ];
    }
}
