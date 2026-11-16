<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Inbox;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Tests\Functional\App\Inbox\Doctrine\RfpEntity;

abstract class BaseRfpResource extends AbstractResource
{
    public static string $type = 'inbRfps';

    public function fields(): array
    {
        return [
            Id::make()->numeric(),
            Str::make('name'),
        ];
    }

    public function getType(mixed $object): string
    {
        return $object instanceof RfpEntity ? static::$type : '';
    }
}
