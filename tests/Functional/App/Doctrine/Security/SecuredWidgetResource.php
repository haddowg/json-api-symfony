<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\Security;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * The `securedWidgets` resource: a default `is_granted('ROLE_USER')` gates read and
 * update, while per-operation `ROLE_ADMIN` overrides gate create and delete — so the
 * per-operation overrides are exercised independently of the default (bundle ADR 0043).
 */
#[AsJsonApiResource(
    entity: SecuredWidgetEntity::class,
    security: "is_granted('ROLE_USER')",
    securityCreate: "is_granted('ROLE_ADMIN')",
    securityDelete: "is_granted('ROLE_ADMIN')",
)]
final class SecuredWidgetResource extends AbstractResource
{
    public static string $type = 'securedWidgets';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
        ];
    }
}
