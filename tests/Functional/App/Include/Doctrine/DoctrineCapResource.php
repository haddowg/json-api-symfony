<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Include\Doctrine;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Include\Resource\BaseCapResource;

/**
 * The Doctrine kernel's `caps` resource (shared {@see BaseCapResource}, the
 * Capability B per-resource max-depth-override witness) mapped to
 * {@see HolderEntity}.
 */
#[AsJsonApiResource(entity: HolderEntity::class)]
final class DoctrineCapResource extends BaseCapResource {}
