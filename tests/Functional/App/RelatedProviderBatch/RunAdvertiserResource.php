<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\RelatedProviderBatch;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(readOnly: true)]
final class RunAdvertiserResource extends AbstractResource
{
    public static string $type = 'runAdvertisers';

    public function fields(): array
    {
        return [
            Id::make()->numeric(),
            Str::make('name'),
        ];
    }
}
