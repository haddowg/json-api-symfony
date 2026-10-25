<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\NamedServer;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * The in-memory twin of {@see DefaultServerSecuredWidgetResource}.
 */
#[AsJsonApiResource(
    server: 'default',
    securityRead: "object.name != 'classified'",
)]
final class InMemoryDefaultServerSecuredWidgetResource extends AbstractResource
{
    public static string $type = 'securedWidgets';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            BelongsTo::make('partner', 'securedWidgets'),
            HasMany::make('partners', 'securedWidgets'),
        ];
    }
}
