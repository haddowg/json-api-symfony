<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\CatalogConsumer;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(readOnly: true)]
final class CapabilityResource extends AbstractResource
{
    public static string $type = 'capabilities';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('method'),
        ];
    }
}
