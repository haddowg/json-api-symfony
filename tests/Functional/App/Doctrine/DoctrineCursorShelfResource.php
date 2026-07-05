<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\BaseCursorShelfResource;

/**
 * The Doctrine kernel's `cursorShelves` resource: the shared
 * {@see BaseCursorShelfResource} mapped to its backing entity via
 * `#[AsJsonApiResource(entity: …)]`, so the related `widgets` fetch routes to
 * the {@see \haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineDataProvider}
 * and its keyset push-down runs as real DQL inside the parent scope (bundle
 * ADR 0063).
 *
 * The `pivotWidgets` relation pins its association entity
 * ({@see CursorShelfWidgetEntity}) so the cursor fetch runs the PIVOT keyset
 * push-down — the `slot` pivot column joins the sort vocabulary and each member
 * renders `meta.pivot` (bundle ADR 0114). `through()` is required here: the
 * shelf's plain `widgets` ManyToMany offers no association entity to
 * auto-detect.
 */
#[AsJsonApiResource(entity: CursorShelfEntity::class)]
final class DoctrineCursorShelfResource extends BaseCursorShelfResource
{
    protected function pivotWidgets(): BelongsToMany
    {
        return parent::pivotWidgets()->through(CursorShelfWidgetEntity::class);
    }
}
