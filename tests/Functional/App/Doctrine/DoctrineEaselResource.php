<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Tests\Functional\App\NestedFieldsets\EaselSerializer;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\BaseBoardResource;

#[AsJsonApiResource(entity: PanelEntity::class, serializer: EaselSerializer::class, readOnly: true)]
final class DoctrineEaselResource extends BaseBoardResource
{
    public static string $type = 'easels';
}
