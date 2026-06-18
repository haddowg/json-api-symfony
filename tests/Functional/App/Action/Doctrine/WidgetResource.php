<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Action\Doctrine;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Action\BaseWidgetResource;

/**
 * The Doctrine `actionWidgets` resource: the shared mount-type declaration mapped to
 * its backing {@see WidgetEntity} via `#[AsJsonApiResource(entity: …)]`, so the type
 * is served by the reference Doctrine provider/persister `-128` fallbacks — the
 * Doctrine half of the custom-action conformance run.
 */
#[AsJsonApiResource(entity: WidgetEntity::class)]
final class WidgetResource extends BaseWidgetResource {}
