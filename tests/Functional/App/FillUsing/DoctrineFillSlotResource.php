<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\FillUsing;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(entity: FillSlot::class)]
final class DoctrineFillSlotResource extends AbstractResource
{
    public static string $type = 'fill-slots';

    public function fields(): array
    {
        return [Id::make()];
    }
}
