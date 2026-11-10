<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\RelationOrder;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

final class OrderMagazineResource extends AbstractResource
{
    public static string $type = 'magazines';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title'),
        ];
    }
}
