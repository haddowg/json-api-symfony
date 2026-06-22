<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\Security;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * The `ownedWidgets` resource: an ownership expression `is_granted('EDIT', object)`
 * gates every operation, resolved by the {@see OwnedWidgetVoter} against the subject
 * entity's `owner`. The keystone use case — per-object authorization backed by a
 * Voter (bundle ADR 0043). A self-referential `parent` relation gives the resource a
 * relationship-mutation endpoint (`…/relationships/parent`) whose write is gated by
 * the same ownership expression against the parent.
 */
#[AsJsonApiResource(
    entity: OwnedWidgetEntity::class,
    security: "is_granted('EDIT', object)",
)]
final class OwnedWidgetResource extends AbstractResource
{
    public static string $type = 'ownedWidgets';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            Str::make('owner'),
            BelongsTo::make('parent', 'ownedWidgets')->nullable(),
        ];
    }
}
