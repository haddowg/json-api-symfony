<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\NestedFieldsets;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\BaseBoardResource;

#[AsJsonApiResource(serializer: EaselSerializer::class, readOnly: true)]
final class EaselResource extends BaseBoardResource
{
    public static string $type = 'easels';
}
