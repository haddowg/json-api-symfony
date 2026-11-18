<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\KeysetDql;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(entity: DivisionEntity::class, readOnly: true)]
final class DoctrineDivisionResource extends BaseDivisionResource {}
