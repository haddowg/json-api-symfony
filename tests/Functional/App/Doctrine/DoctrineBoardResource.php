<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Tests\Functional\App\FieldSelection\BoardSerializer;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\BaseBoardResource;

#[AsJsonApiResource(entity: PanelEntity::class, serializer: BoardSerializer::class, readOnly: true)]
final class DoctrineBoardResource extends BaseBoardResource
{
    public static string $type = 'boards';
}
