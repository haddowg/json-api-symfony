<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Hook;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * The shared widget declaration for the lifecycle-hooks suite: a writable `name`,
 * a `stamp` a before-create hook sets, and a to-one `owner` relationship the
 * relationship-mutation hooks exercise. Subclassed by the event-path
 * {@see HookWidgetResource} and the resource-method-path {@see HookableWidgetResource}
 * (which carries the same fields but a different `$type`).
 */
abstract class BaseHookWidgetResource extends AbstractResource
{
    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            Str::make('stamp'),
            BelongsTo::make('owner', 'hookOwners'),
        ];
    }
}
