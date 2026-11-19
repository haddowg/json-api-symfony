<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ThroughAccess\Doctrine;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Tests\Functional\App\ThroughAccess\BaseCrateResource;

#[AsJsonApiResource(entity: CrateEntity::class, readOnly: true, security: "is_granted('ROLE_USER')")]
final class DoctrineCrateResource extends BaseCrateResource {}
