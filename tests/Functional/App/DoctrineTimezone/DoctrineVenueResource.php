<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\DoctrineTimezone;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\BaseVenueResource;

#[AsJsonApiResource(entity: VenueEntity::class, readOnly: true)]
final class DoctrineVenueResource extends BaseVenueResource {}
