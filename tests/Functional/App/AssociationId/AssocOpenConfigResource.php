<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\AssociationId;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * The same configs with no query extension, so an include of them windows natively.
 */
#[AsJsonApiResource(entity: AssocConfigEntity::class)]
final class AssocOpenConfigResource extends AbstractResource
{
    public static string $type = 'open-configs';

    public function fields(): array
    {
        return [
            Id::make()->numeric(),
            Str::make('label')->sortable(),
        ];
    }
}
