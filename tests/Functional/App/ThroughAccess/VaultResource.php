<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ThroughAccess;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(readOnly: true, security: "is_granted('ROLE_USER')", securityList: "is_granted('ROLE_ADMIN')")]
final class VaultResource extends BaseVaultResource {}
