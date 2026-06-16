<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Include\Doctrine;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Include\Resource\BaseNodeResource;

/**
 * The Doctrine kernel's `nodes` resource: the shared {@see BaseNodeResource}
 * declaration mapped to {@see NodeEntity} so the include safeguards run over the
 * real Doctrine read + batch-preload path.
 */
#[AsJsonApiResource(entity: NodeEntity::class)]
final class DoctrineNodeResource extends BaseNodeResource {}
