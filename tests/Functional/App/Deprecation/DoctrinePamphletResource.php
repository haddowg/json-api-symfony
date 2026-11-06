<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Deprecation;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(entity: Bulletin::class, readOnly: true, deprecation: '@2556143999', sunset: 'Sat, 31 Dec 2050 23:59:59 GMT')]
final class DoctrinePamphletResource extends PlainResource
{
    public static string $type = 'pamphlets';
}
