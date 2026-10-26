<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\FillUsing;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

class FillAgencyResource extends AbstractResource
{
    public static string $type = 'fill-agencies';

    public function getType(mixed $object): string
    {
        return $object instanceof FillMember ? 'fill-members' : static::$type;
    }

    public function fields(): array
    {
        return [Id::make(), Str::make('name')];
    }
}
