<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\OperationExamples;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(server: 'counted', readOnly: true)]
final class InMemoryJottingResource extends JottingResource {}
