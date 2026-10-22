<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\DoctrineTimezone;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\BaseGigStartResource;

#[AsJsonApiResource(entity: GigEntity::class, server: 'raw', readOnly: true)]
final class DoctrineRawGigResource extends BaseGigStartResource
{
    public static string $type = 'raw-gigs';
}
