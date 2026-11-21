<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ThroughAccess\Doctrine;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Tests\Functional\App\ThroughAccess\BaseSafeResource;

#[AsJsonApiResource(entity: SafeEntity::class, readOnly: true, security: "is_granted('ROLE_USER')", securityRead: "is_granted('ROLE_ADMIN')")]
final class DoctrineSafeResource extends BaseSafeResource {}
