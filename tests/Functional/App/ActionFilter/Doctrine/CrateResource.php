<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ActionFilter\Doctrine;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Tests\Functional\App\ActionFilter\BaseCrateResource;

#[AsJsonApiResource(entity: CrateEntity::class, readOnly: true, securityList: "is_granted('ROLE_ADMIN')")]
final class CrateResource extends BaseCrateResource {}
