<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IdMatrix;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(entity: ImUlidOwner::class)]
final class DoctrineImUlidOwnerResource extends ImUlidOwnerResource {}
