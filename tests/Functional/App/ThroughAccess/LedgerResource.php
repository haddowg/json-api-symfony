<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ThroughAccess;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(readOnly: true)]
final class LedgerResource extends BaseLedgerResource {}
