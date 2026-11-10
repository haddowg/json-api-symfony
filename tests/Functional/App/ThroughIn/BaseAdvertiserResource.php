<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ThroughIn;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Tests\Functional\App\ThroughIn\Doctrine\AdvertiserEntity;

/**
 * Advertisers keyed by a numeric id, so a filter ending on one validates its values
 * as integers.
 */
abstract class BaseAdvertiserResource extends AbstractResource
{
    public static string $type = 'wtiAdvertisers';

    public function fields(): array
    {
        return [
            Id::make()->numeric(),
            Str::make('name'),
        ];
    }

    public function getType(mixed $object): string
    {
        return $object instanceof AdvertiserEntity ? static::$type : '';
    }
}
