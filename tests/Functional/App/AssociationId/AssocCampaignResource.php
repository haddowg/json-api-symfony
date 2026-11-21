<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\AssociationId;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * The campaign a config is keyed by, served as its own type with its own scope, which a
 * path reaching a config must not apply.
 */
#[AsJsonApiResource(entity: AssocCampaignEntity::class)]
final class AssocCampaignResource extends AbstractResource
{
    public static string $type = 'campaigns';

    public function fields(): array
    {
        return [
            Id::make()->numeric(),
            Str::make('name'),
        ];
    }
}
