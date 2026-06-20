<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\BaseBadgeResource;

/**
 * The Doctrine `badges` resource: the request-aware-predicate fixture
 * ({@see BaseBadgeResource}) mapped to {@see BadgeEntity} via
 * `#[AsJsonApiResource(entity: …)]`, served by the bundle's `-128` fallback
 * Doctrine provider/persister. The same predicates the in-memory witness exercises
 * must hold here, proving they are provider-agnostic.
 */
#[AsJsonApiResource(entity: BadgeEntity::class)]
final class DoctrineBadgeResource extends BaseBadgeResource {}
