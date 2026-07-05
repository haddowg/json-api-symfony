<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Resource;

/**
 * The in-memory kernel's `cursorShelves` resource: the shared
 * {@see BaseCursorShelfResource} served over the in-memory provider — the
 * conformance witness half of the related-collection cursor (keyset)
 * dual-provider contract.
 */
final class CursorShelfResource extends BaseCursorShelfResource {}
