<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Sparse;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * A `sparseWidgets` resource whose `expensiveScore` attribute is
 * {@see \haddowg\JsonApi\Resource\Field\AbstractField::sparseByDefault()}: omitted
 * from the default response and rendered only when the client names it in
 * `fields[sparseWidgets]`. The bundle witness that core's sparse-by-default field
 * tier (core ADR 0117) flows through the bundle's serializer → transformer → response
 * stack over HTTP.
 */
final class SparseWidgetResource extends AbstractResource
{
    public static string $type = 'sparseWidgets';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            Integer::make('expensiveScore')->sparseByDefault(),
        ];
    }
}
