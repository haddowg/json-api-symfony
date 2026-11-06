<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Deprecation;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(entity: Bulletin::class, deprecatedOperations: ['delete', 'fetchRelated.parent'])]
final class DoctrineBulletinResource extends BulletinResource {}
