<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\MorphKeys;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Tests\Functional\App\MorphKeys\Doctrine\MkCodeEntity;

/**
 * Codes with string ids, hidden ones left out.
 */
abstract class BaseMkCodeResource extends AbstractResource
{
    public static string $type = 'mkCodes';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
        ];
    }

    public function getType(mixed $object): string
    {
        return $object instanceof MkCodeEntity ? static::$type : '';
    }
}
