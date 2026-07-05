<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Composite;

use haddowg\JsonApiBundle\Tests\Functional\App\Resource\BaseCompositeWidgetResource;

/**
 * The in-memory half of the composite conformance pair: the shared
 * {@see BaseCompositeWidgetResource} declaration served over the in-memory
 * provider ({@see CompositeInMemoryTestKernel}).
 */
final class CompositeWidgetResource extends BaseCompositeWidgetResource {}
