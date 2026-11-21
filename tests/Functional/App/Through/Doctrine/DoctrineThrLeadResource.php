<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Through\Doctrine;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Through\BaseThrLeadResource;

#[AsJsonApiResource(entity: ThrLeadEntity::class, readOnly: true)]
final class DoctrineThrLeadResource extends BaseThrLeadResource {}
