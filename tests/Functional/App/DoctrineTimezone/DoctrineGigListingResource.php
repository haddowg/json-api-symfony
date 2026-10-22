<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\DoctrineTimezone;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\BaseGigStartResource;

#[AsJsonApiResource(entity: GigEntity::class, server: 'tokyo', readOnly: true)]
final class DoctrineGigListingResource extends BaseGigStartResource
{
    public static string $type = 'gig-listings';
}
