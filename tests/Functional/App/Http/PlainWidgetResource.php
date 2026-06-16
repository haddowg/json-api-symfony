<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Http;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * The control witness (bundle ADR 0054): a resource that declares no response
 * headers at all. Under a kernel with no `json_api.defaults`, its reads get no
 * `Cache-Control` and no deprecation (unchanged behaviour); under a kernel that
 * does declare defaults, it inherits them.
 */
final class PlainWidgetResource extends AbstractResource
{
    public static string $type = 'plainWidgets';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
        ];
    }
}
