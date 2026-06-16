<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\BaseTagResource;

/**
 * The Doctrine kernel's `tags` resource: the shared {@see BaseTagResource}
 * declaration mapped to its backing entity via `#[AsJsonApiResource(entity: …)]`
 * — the *only* code added to serve a new type over the Doctrine path, since the
 * `-128` fallback provider/persister handle it from the entity map (the capstone
 * genericity proof; ADR 0021).
 */
#[AsJsonApiResource(entity: TagEntity::class)]
final class DoctrineTagResource extends BaseTagResource {}
