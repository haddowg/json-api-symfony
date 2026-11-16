<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\FirstPage;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

abstract class FpLabelResource extends AbstractResource
{
    public static string $type = 'fp-labels';

    public function getType(mixed $object): string
    {
        return $object instanceof FpLabel ? static::$type : '';
    }

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
        ];
    }
}
