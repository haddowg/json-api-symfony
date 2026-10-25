<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\NamedServer;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\Security\SecuredWidgetEntity;

/**
 * The Doctrine `securedWidgets` the default server serves beside the named server's
 * {@see \haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\Security\SecuredWidgetResource}.
 * It declares only a read gate, which refuses everyone the widget named "classified",
 * so neither server's declarations may reach the other.
 */
#[AsJsonApiResource(
    entity: SecuredWidgetEntity::class,
    server: 'default',
    securityRead: "object.name != 'classified'",
)]
final class DefaultServerSecuredWidgetResource extends AbstractResource
{
    public static string $type = 'securedWidgets';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            BelongsTo::make('partner', 'securedWidgets')->nullable(),
            HasMany::make('partners', 'securedWidgets'),
        ];
    }
}
