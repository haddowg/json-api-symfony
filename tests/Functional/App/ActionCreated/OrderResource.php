<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ActionCreated;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(readOnly: true)]
final class OrderResource extends AbstractResource
{
    public static string $type = 'createdOrders';

    public function fields(): array
    {
        return [Id::make(), Str::make('reference')];
    }
}
