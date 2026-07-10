<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\BaseCursorGroupResource;

/**
 * The Doctrine kernel's `cursorGroups` resource: the shared {@see BaseCursorGroupResource}
 * mapped to its backing entity via `#[AsJsonApiResource(entity: …)]`, so the related
 * `widgets` fetch routes to the
 * {@see \haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineDataProvider}. The `widgets`
 * relation is the INVERSE side of {@see CursorGroupEntity}'s `OneToMany` (the related
 * {@see CursorWidgetEntity} carries the owning `group_id` FK), so a cursor-resolved include
 * collapses to the inverse-FK single-window shape (bundle ADR 0118).
 */
#[AsJsonApiResource(entity: CursorGroupEntity::class)]
final class DoctrineCursorGroupResource extends BaseCursorGroupResource {}
