<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Through\Doctrine;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Through\BaseBrokenThrResource;

#[AsJsonApiResource(entity: ThrLeadEntity::class, readOnly: true)]
final class DoctrineBrokenThrResource extends BaseBrokenThrResource {}
