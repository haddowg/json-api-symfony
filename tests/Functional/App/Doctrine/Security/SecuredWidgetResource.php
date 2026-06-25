<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\Security;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * The `securedWidgets` resource: a default `is_granted('ROLE_USER')` gates read and
 * update, while per-operation `ROLE_ADMIN` overrides gate create and delete — so the
 * per-operation overrides are exercised independently of the default (bundle ADR 0043).
 *
 * It is also the Doctrine twin of the in-memory
 * {@see \haddowg\JsonApiBundle\Tests\Functional\App\Security\InMemorySecuredWidgetResource}
 * for **per-relation security** (core ADR 0099 / bundle ADR 0100) — a relation
 * authorized independently of its parent. Four self-relations share the `partner`
 * backing, differing only in their gate:
 *  - `partner`       inherits the parent's read/update gate (the baseline);
 *  - `publicPartner` `security(read: false)` — PUBLIC read, MORE permissive than the
 *                    `ROLE_USER` parent (reachable unauthenticated);
 *  - `adminPartner`  `security(read: ROLE_ADMIN)` — admin-only read, MORE restrictive;
 *  - `lockedPartner` `security(mutate: ROLE_ADMIN)` — read inherits, but mutation is
 *                    admin-only, MORE restrictive than the `ROLE_USER` update default.
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
            BelongsTo::make('partner', 'securedWidgets')->nullable(),
            BelongsTo::make('publicPartner', 'securedWidgets')->storedAs('partner')->nullable()->security(read: false),
            BelongsTo::make('adminPartner', 'securedWidgets')->storedAs('partner')->nullable()->security(read: "is_granted('ROLE_ADMIN')"),
            BelongsTo::make('lockedPartner', 'securedWidgets')->storedAs('partner')->nullable()->security(mutate: "is_granted('ROLE_ADMIN')"),
        ];
    }
}
