<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ComputedPivot;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(entity: CpFleet::class, readOnly: true)]
final class DoctrineCpFleetResource extends CpFleetResource {}
