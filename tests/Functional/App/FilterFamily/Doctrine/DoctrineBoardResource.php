<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\FilterFamily\Doctrine;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Tests\Functional\App\FilterFamily\BaseBoardResource;

#[AsJsonApiResource(entity: BoardEntity::class, readOnly: true)]
final class DoctrineBoardResource extends BaseBoardResource {}
