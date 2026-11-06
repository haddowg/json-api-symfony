<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Deprecation;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(readOnly: true, sunset: '2051-06-30', deprecatedOperations: ['fetchOne'])]
final class InMemoryLeafletResource extends PlainResource
{
    public static string $type = 'leaflets';
}
