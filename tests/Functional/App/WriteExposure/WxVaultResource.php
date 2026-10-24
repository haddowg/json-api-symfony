<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\WriteExposure;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * Registered on the `internal` server only.
 */
#[AsJsonApiResource(entity: WxVaultEntity::class, server: 'internal')]
final class WxVaultResource extends AbstractResource
{
    public static string $type = 'wx-vaults';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            BelongsTo::make('owner', 'wx-people'),
        ];
    }
}
