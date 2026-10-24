<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ExpectResource;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(entity: Advertiser::class, readOnly: true)]
final class AdvertiserResource extends AbstractResource
{
    public static string $type = 'advertisers';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
        ];
    }
}
