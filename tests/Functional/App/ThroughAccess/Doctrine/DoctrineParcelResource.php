<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ThroughAccess\Doctrine;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Tests\Functional\App\ThroughAccess\BaseParcelResource;

#[AsJsonApiResource(entity: ParcelEntity::class, readOnly: true, security: "is_granted('ROLE_USER')")]
final class DoctrineParcelResource extends BaseParcelResource {}
