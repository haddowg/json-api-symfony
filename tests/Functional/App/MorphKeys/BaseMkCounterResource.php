<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\MorphKeys;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Tests\Functional\App\MorphKeys\Doctrine\MkCounterEntity;

/**
 * Counters with plain integer ids, hidden ones left out.
 */
abstract class BaseMkCounterResource extends AbstractResource
{
    public static string $type = 'mkCounters';

    public function fields(): array
    {
        return [
            Id::make()->numeric(),
            Str::make('name'),
        ];
    }

    public function getType(mixed $object): string
    {
        return $object instanceof MkCounterEntity ? static::$type : '';
    }
}
