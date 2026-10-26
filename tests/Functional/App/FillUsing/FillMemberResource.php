<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\FillUsing;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

class FillMemberResource extends AbstractResource
{
    public static string $type = 'fill-members';

    public function getType(mixed $object): string
    {
        return $object instanceof FillAgency ? 'fill-agencies' : static::$type;
    }

    public function fields(): array
    {
        return [Id::make(), Str::make('name')];
    }
}
