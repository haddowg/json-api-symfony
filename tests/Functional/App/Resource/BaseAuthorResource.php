<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Resource;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\Where;

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
}
