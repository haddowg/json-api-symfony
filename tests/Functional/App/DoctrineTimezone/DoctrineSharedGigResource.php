<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\DoctrineTimezone;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\BaseSharedGigResource;

#[AsJsonApiResource(entity: GigEntity::class, server: ['default', 'tokyo', 'raw'], readOnly: true)]
final class DoctrineSharedGigResource extends BaseSharedGigResource {}
