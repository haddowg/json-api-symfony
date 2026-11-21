<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Through;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(readOnly: true)]
final class ThrLeadResource extends BaseThrLeadResource {}
