<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Deprecation;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(entity: Bulletin::class, readOnly: true, sunset: '2051-06-30', deprecatedOperations: ['fetchOne'])]
final class DoctrineLeafletResource extends PlainResource
{
    public static string $type = 'leaflets';
}
