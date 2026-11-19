<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ThroughAccess\Doctrine;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Tests\Functional\App\ThroughAccess\BaseVaultResource;

#[AsJsonApiResource(entity: VaultEntity::class, readOnly: true, security: "is_granted('ROLE_USER')", securityList: "is_granted('ROLE_ADMIN')")]
final class DoctrineVaultResource extends BaseVaultResource {}
