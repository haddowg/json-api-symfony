<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Include\Doctrine;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Include\Resource\BaseRootResource;

/**
 * The Doctrine kernel's `roots` resource (shared {@see BaseRootResource}, the
 * Capability C allow-list witness) mapped to {@see HolderEntity}.
 */
#[AsJsonApiResource(entity: HolderEntity::class)]
final class DoctrineRootResource extends BaseRootResource {}
