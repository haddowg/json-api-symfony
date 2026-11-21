<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ThroughAccess\Doctrine;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Tests\Functional\App\ThroughAccess\BaseLedgerResource;

#[AsJsonApiResource(entity: LedgerEntity::class, readOnly: true)]
final class DoctrineLedgerResource extends BaseLedgerResource {}
