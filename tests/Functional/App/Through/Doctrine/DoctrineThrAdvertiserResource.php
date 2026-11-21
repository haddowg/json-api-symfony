<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Through\Doctrine;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Through\BaseThrAdvertiserResource;

#[AsJsonApiResource(entity: ThrAdvertiserEntity::class, readOnly: true)]
final class DoctrineThrAdvertiserResource extends BaseThrAdvertiserResource {}
