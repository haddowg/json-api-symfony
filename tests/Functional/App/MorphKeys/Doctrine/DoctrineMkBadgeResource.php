<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\MorphKeys\Doctrine;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Tests\Functional\App\MorphKeys\BaseMkBadgeResource;

#[AsJsonApiResource(entity: MkBadgeEntity::class, readOnly: true)]
final class DoctrineMkBadgeResource extends BaseMkBadgeResource {}
