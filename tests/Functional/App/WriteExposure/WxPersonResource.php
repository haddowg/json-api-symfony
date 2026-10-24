<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\WriteExposure;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(entity: WxPersonEntity::class, server: ['default', 'internal'])]
final class WxPersonResource extends AbstractResource
{
    public static string $type = 'wx-people';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
        ];
    }
}
