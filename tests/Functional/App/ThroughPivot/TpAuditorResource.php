<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ThroughPivot;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * Auditors, whose collection only an admin may list, so a path reaching them is refused
 * to anyone else.
 */
#[AsJsonApiResource(entity: TpAuditorEntity::class, readOnly: true, security: "is_granted('ROLE_USER')", securityList: "is_granted('ROLE_ADMIN')")]
final class TpAuditorResource extends AbstractResource
{
    public static string $type = 'tpAuditors';

    public function fields(): array
    {
        return [Id::make()->numeric(), Str::make('name')];
    }
}
