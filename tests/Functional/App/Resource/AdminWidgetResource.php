<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Resource;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * The admin-only resource of the multi-server witness (ADR 0034): exposed on the
 * named `admin` server alone (`server: 'admin'`), so its routes mount only under
 * the admin import's `/admin` prefix.
 */
#[AsJsonApiResource(server: 'admin')]
final class AdminWidgetResource extends AbstractResource
{
    public static string $type = 'admin-widgets';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            BelongsTo::make('related', 'admin-widgets'),
        ];
    }
}
