<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\BaseCompositeWidgetResource;

/**
 * The Doctrine half of the composite conformance pair: the shared
 * {@see BaseCompositeWidgetResource} declaration mapped to
 * {@see CompositeWidgetEntity}, whose composite attributes are single `json`
 * columns — so the same assertions witness the json-column round-trip.
 */
#[AsJsonApiResource(entity: CompositeWidgetEntity::class)]
final class DoctrineCompositeWidgetResource extends BaseCompositeWidgetResource {}
