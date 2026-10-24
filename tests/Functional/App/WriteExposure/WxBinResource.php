<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\WriteExposure;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Operation\Operation;

#[AsJsonApiResource(entity: WxShelfEntity::class, operations: [Operation::FetchCollection, Operation::FetchOne, Operation::Create, Operation::Delete])]
final class WxBinResource extends AbstractResource
{
    public static string $type = 'wx-bins';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            BelongsTo::make('owner', 'wx-people'),
            HasMany::make('members', 'wx-people'),
        ];
    }
}
