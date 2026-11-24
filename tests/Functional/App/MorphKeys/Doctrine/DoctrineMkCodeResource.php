<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\MorphKeys\Doctrine;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Tests\Functional\App\MorphKeys\BaseMkCodeResource;

#[AsJsonApiResource(entity: MkCodeEntity::class, readOnly: true)]
final class DoctrineMkCodeResource extends BaseMkCodeResource {}
