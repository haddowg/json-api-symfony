<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Include\Doctrine;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Include\Resource\BaseTagResource;

/**
 * The Doctrine kernel's `tags` resource (shared {@see BaseTagResource}) mapped to
 * {@see TagEntity}.
 */
#[AsJsonApiResource(entity: TagEntity::class)]
final class DoctrineTagResource extends BaseTagResource {}
