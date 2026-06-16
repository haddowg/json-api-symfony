<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Resource;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Sort\SortByField;

/**
 * The in-memory far `tracks` resource for the pivot boundary witness — a `title`
 * attribute plus its own `filter[title]`/`sort=title` (which DO work on the
 * related endpoint, proving only the PIVOT keys are unrecognised in-memory).
 */
final class PivotTrackResource extends AbstractResource
{
    public static string $type = 'tracks';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title'),
        ];
    }

    public function filters(): array
    {
        return [
            Where::make('title', 'title', 'like'),
        ];
    }

    public function sorts(): array
    {
        return [
            SortByField::make('title', 'title'),
        ];
    }
}
