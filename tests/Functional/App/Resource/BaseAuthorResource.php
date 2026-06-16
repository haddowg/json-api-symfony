<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Resource;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApi\Resource\Sort\SortDirective;

/**
 * The shared `authors` declaration both functional kernels serve — the related
 * type an article's to-one `author` relationship links to. Minimal: an id and a
 * single `name` attribute. Registering it makes the type known to the
 * serializer resolver, so {@see \haddowg\JsonApi\Resource\Field\BelongsTo} can
 * emit `{type: 'authors', id: …}` linkage.
 *
 * `name` is sortable and filterable: this is the related vocabulary the
 * `editors` (and `author`) related-collection endpoint resolves filter/sort
 * against, so the many-to-many subquery scope can be ordered and narrowed.
 *
 * It also declares a `defaultSort()` on `name` — a column that lives on the
 * AUTHOR entity, never on the article parent. This makes `authors` the related
 * type of a countable to-many (`articles.editors`) whose related resource carries
 * a default order, the regression witness for the Doctrine `?withCount` count: a
 * count needs no order and roots on the parent, so the related resource's default
 * order MUST be dropped from the count criteria — otherwise the grouped count
 * would emit `ORDER BY parent.name`, a related-entity column against the parent
 * root, which a SQL engine rejects (bundle ADR 0060). The default order still
 * applies to the real `/authors` and `/articles/{id}/editors` fetches (every
 * order-asserting test there passes an explicit `?sort`, which overrides it).
 */
abstract class BaseAuthorResource extends AbstractResource
{
    public static string $type = 'authors';

    public function fields(): array
    {
        return [
            // Store-provided id: a database auto-increment assigns it (core ADR 0048).
            Id::make(),
            Str::make('name')->sortable(),
        ];
    }

    public function filters(): array
    {
        return [
            Where::make('name'),
        ];
    }

    public function defaultSort(): array
    {
        return [new SortDirective(SortByField::make('name'), descending: false)];
    }
}
