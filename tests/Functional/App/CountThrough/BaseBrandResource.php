<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\CountThrough;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Tests\Functional\App\CountThrough\Doctrine\BrandEntity;

abstract class BaseBrandResource extends AbstractResource
{
    public static string $type = 'ctBrands';

    public function fields(): array
    {
        return [
            Id::make()->numeric(),
            Str::make('name'),
        ];
    }

    public function getType(mixed $object): string
    {
        return $object instanceof BrandEntity ? static::$type : '';
    }
}
