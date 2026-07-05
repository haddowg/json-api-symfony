<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\BaseCursorShelfResource;

/**
 * The Doctrine kernel's `cursorShelves` resource: the shared
 * {@see BaseCursorShelfResource} mapped to its backing entity via
 * `#[AsJsonApiResource(entity: …)]`, so the related `widgets` fetch routes to
 * the {@see \haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineDataProvider}
 * and its keyset push-down runs as real DQL inside the parent scope (bundle
 * ADR 0063).
 */
#[AsJsonApiResource(entity: CursorShelfEntity::class)]
final class DoctrineCursorShelfResource extends BaseCursorShelfResource {}
