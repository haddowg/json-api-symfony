<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\RequiredRelation;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(entity: Task::class)]
final class DoctrineTaskResource extends TaskResource {}
