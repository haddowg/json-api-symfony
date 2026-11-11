<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\MappedOrder;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

final class MoBookResource extends AbstractResource
{
    public static string $type = 'moBooks';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title')->sortable(),
        ];
    }
}
