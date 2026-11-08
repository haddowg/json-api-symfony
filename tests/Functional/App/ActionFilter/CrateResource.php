<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ActionFilter;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(readOnly: true, securityList: "is_granted('ROLE_ADMIN')")]
final class CrateResource extends BaseCrateResource {}
