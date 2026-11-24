<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\MorphKeys\Doctrine;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Tests\Functional\App\MorphKeys\BaseMkCounterResource;

#[AsJsonApiResource(entity: MkCounterEntity::class, readOnly: true)]
final class DoctrineMkCounterResource extends BaseMkCounterResource {}
