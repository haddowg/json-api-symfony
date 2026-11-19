<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\TypedRequestMeta;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

abstract class TrPlainResource extends AbstractResource
{
    public static string $type = 'tr-plains';

    public function getType(mixed $object): string
    {
        return $object instanceof TrPlain ? static::$type : '';
    }

    public function fields(): array
    {
        return [Id::make(), Str::make('name')];
    }

    public function getMeta(mixed $object, JsonApiRequestInterface $request): array
    {
        return ['free' => true];
    }
}
