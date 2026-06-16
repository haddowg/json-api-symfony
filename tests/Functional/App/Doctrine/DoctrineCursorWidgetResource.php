<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\BaseCursorWidgetResource;

/**
 * The Doctrine kernel's `cursorWidgets` resource: the shared
 * {@see BaseCursorWidgetResource} mapped to its backing entity via
 * `#[AsJsonApiResource(entity: …)]`, so the type routes to the
 * {@see \haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineDataProvider} and
 * its keyset push-down runs as real DQL (bundle ADR 0063).
 */
#[AsJsonApiResource(entity: CursorWidgetEntity::class)]
final class DoctrineCursorWidgetResource extends BaseCursorWidgetResource {}
