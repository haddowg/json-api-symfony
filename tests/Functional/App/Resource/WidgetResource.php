<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Resource;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * The parent of the serialize-only witness: a normal `widgets` resource whose
 * `color` relation points at the resource-less `colors` type (ADR 0024). The
 * relation renders linkage / `?include` through the standalone
 * {@see \haddowg\JsonApiBundle\Tests\Functional\App\ColorSerializer}.
 */
final class WidgetResource extends AbstractResource
{
    public static string $type = 'widgets';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            BelongsTo::make('color', 'colors'),
        ];
    }
}
