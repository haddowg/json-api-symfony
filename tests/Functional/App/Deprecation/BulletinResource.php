<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Deprecation;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Sort\SortByField;

/**
 * Deprecates members rather than the type: the `code` attribute, the `editor` relation,
 * the `code` filter and the `legacyTitle` sort. The concrete classes deprecate the
 * `delete` and `fetchRelated.parent` operations.
 */
abstract class BulletinResource extends AbstractResource
{
    public static string $type = 'bulletins';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title')->sortable(),
            Str::make('code')->deprecated(),
            BelongsTo::make('parent', 'bulletins')->nullable(),
            BelongsTo::make('editor', 'bulletins')->nullable()->deprecated(),
        ];
    }

    public function filters(): array
    {
        return [
            Where::make('title'),
            Where::make('code')->deprecated(),
        ];
    }

    public function sorts(): array
    {
        return [
            SortByField::make('legacyTitle', 'title')->deprecated(),
        ];
    }
}
