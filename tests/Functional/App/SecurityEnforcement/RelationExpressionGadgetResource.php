<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\SecurityEnforcement;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * Declares an enforced read expression on a relation only.
 */
#[AsJsonApiResource(readOnly: true)]
final class RelationExpressionGadgetResource extends AbstractResource
{
    public static string $type = 'guard-gadgets';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            BelongsTo::make('partner', 'guard-gadgets')->security(read: "is_granted('ROLE_ADMIN')"),
        ];
    }
}
