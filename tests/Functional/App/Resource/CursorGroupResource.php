<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Resource;

/**
 * The in-memory kernel's `cursorGroups` resource: the shared
 * {@see BaseCursorGroupResource} served over the in-memory provider — the conformance
 * witness half of the inverse-FK cursor (keyset) include dual-provider contract.
 */
final class CursorGroupResource extends BaseCursorGroupResource {}
