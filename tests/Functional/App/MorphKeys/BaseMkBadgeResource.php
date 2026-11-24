<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\MorphKeys;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Tests\Functional\App\MorphKeys\Doctrine\MkBadgeEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\NamedServer\PrefixIdEncoder;

/**
 * Badges whose integer key is served as `b-<id>`, hidden ones left out.
 */
abstract class BaseMkBadgeResource extends AbstractResource
{
    public static string $type = 'mkBadges';

    public function fields(): array
    {
        return [
            Id::make()->encodeUsing(new PrefixIdEncoder('b-')),
            Str::make('name'),
        ];
    }

    public function getType(mixed $object): string
    {
        return $object instanceof MkBadgeEntity ? static::$type : '';
    }
}
