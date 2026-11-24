<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\MorphKeys;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Tests\Functional\App\MorphKeys\Doctrine\MkTokenEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\NamedServer\PrefixIdEncoder;

/**
 * Tokens whose integer key is served as `t-<id>`, none hidden.
 */
abstract class BaseMkTokenResource extends AbstractResource
{
    public static string $type = 'mkTokens';

    public function fields(): array
    {
        return [
            Id::make()->encodeUsing(new PrefixIdEncoder('t-')),
            Str::make('name'),
        ];
    }

    public function getType(mixed $object): string
    {
        return $object instanceof MkTokenEntity ? static::$type : '';
    }
}
