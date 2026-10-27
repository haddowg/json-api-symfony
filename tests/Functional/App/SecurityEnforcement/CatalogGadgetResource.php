<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\SecurityEnforcement;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * One rule of each level for the operation catalogue: the collection needs an admin, a
 * single read depends on the gadget, and the partner relation is public.
 */
#[AsJsonApiResource(
    readOnly: true,
    securityRead: "is_granted('ROLE_ADMIN') or object.name == user.getUserIdentifier()",
    securityList: "is_granted('ROLE_ADMIN')",
)]
final class CatalogGadgetResource extends AbstractResource
{
    public static string $type = 'guard-gadgets';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            BelongsTo::make('partner', 'guard-gadgets')->security(read: false),
        ];
    }
}
