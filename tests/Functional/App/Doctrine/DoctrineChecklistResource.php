<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\BaseChecklistResource;

#[AsJsonApiResource(entity: ChecklistEntity::class)]
final class DoctrineChecklistResource extends BaseChecklistResource {}
