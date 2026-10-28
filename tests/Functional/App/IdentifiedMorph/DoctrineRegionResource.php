<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IdentifiedMorph;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(entity: IdentifiedRegion::class, readOnly: true)]
final class DoctrineRegionResource extends RegionResource {}
