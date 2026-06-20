<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\BaseMedalResource;

/**
 * The Doctrine far (related) `medals` resource ({@see BaseMedalResource}) mapped to
 * {@see MedalEntity}, served by the bundle's `-128` fallback Doctrine
 * provider/persister.
 */
#[AsJsonApiResource(entity: MedalEntity::class)]
final class DoctrineMedalResource extends BaseMedalResource {}
