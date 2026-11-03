<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\BasePipelineResource;

#[AsJsonApiResource(entity: PipelineEntity::class)]
final class DoctrinePipelineResource extends BasePipelineResource {}
