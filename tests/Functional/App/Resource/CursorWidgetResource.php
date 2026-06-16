<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Resource;

/**
 * The in-memory kernel's `cursorWidgets` resource: the shared
 * {@see BaseCursorWidgetResource} served over the in-memory provider — the
 * conformance witness half of the cursor (keyset) dual-provider contract.
 */
final class CursorWidgetResource extends BaseCursorWidgetResource {}
