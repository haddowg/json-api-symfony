<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\IdMatrix;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

#[AsJsonApiResource(entity: ImIntOwner::class)]
final class DoctrineImIntOwnerResource extends ImIntOwnerResource {}
