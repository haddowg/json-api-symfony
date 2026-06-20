<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\BaseLeafletResource;

/**
 * The Doctrine primary `leaflets` resource ({@see BaseLeafletResource}) mapped to
 * {@see LeafletEntity}, served by the bundle's `-128` fallback Doctrine provider.
 */
#[AsJsonApiResource(entity: LeafletEntity::class)]
final class DoctrineLeafletResource extends BaseLeafletResource {}
