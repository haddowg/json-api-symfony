<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\SecurityEnforcement;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * Declares only the documentation-only true/false values, which need no authorization checker.
 */
#[AsJsonApiResource(readOnly: true, security: true, securityList: false)]
final class DocumentedGadgetResource extends AbstractResource
{
    public static string $type = 'guard-gadgets';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            BelongsTo::make('partner', 'guard-gadgets')->security(read: true, mutate: false),
        ];
    }
}
