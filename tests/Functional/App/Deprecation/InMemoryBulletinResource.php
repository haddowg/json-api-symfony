<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Deprecation;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(deprecatedOperations: ['delete', 'fetchRelated.parent'])]
final class InMemoryBulletinResource extends BulletinResource {}
