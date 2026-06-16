<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App;

/**
 * The in-memory `cursorWidgets` model for the cursor (keyset) conformance suite —
 * the same shape as the Doctrine
 * {@see \haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\CursorWidgetEntity}.
 *
 * It carries a non-unique sortable `category` (deliberate ties so the appended
 * PK tiebreak is exercised), a NULLABLE sortable `priority` (some rows null,
 * some not — the null-branch ground truth), and a NULLABLE sortable
 * `releasedAt` datetime (the date-keyed + typed-binding case). The `id` is the
 * keyset primary key.
 */
final class CursorWidget
{
    public function __construct(
        public ?int $id = null,
        public string $category = '',
        public ?int $priority = null,
        public ?\DateTimeImmutable $releasedAt = null,
    ) {}
}
