<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\Security;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
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
    securityRead: "is_granted('ROLE_USER') and (object.name != 'classified' or is_granted('ROLE_ADMIN'))",
    securityList: "is_granted('ROLE_USER') and not is_granted('ROLE_NO_LIST')",
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
            // Flattened from the partner, so it reads null when the partner is a row the
            // caller may not read.
            Str::make('partnerName')->storedAs('name')->on('partner'),
            BelongsTo::make('partner', 'securedWidgets')->nullable(),
            BelongsTo::make('publicPartner', 'securedWidgets')->storedAs('partner')->nullable()->security(read: false),
            BelongsTo::make('adminPartner', 'securedWidgets')->storedAs('partner')->nullable()->security(read: "is_granted('ROLE_ADMIN')"),
            BelongsTo::make('lockedPartner', 'securedWidgets')->storedAs('partner')->nullable()->security(mutate: "is_granted('ROLE_ADMIN')"),
            HasMany::make('partners', 'securedWidgets')->countable(),
            // Hidden from everyone but an admin, so a non-admin gets a 404 at its endpoints.
            BelongsTo::make('staffPartner', 'securedWidgets')->storedAs('partner')->nullable()->hidden(
                static fn(mixed $model, JsonApiRequestInterface $request): bool => $request->getHeaderLine('Authorization') !== 'Bearer admin',
            ),
            // A pivot relation only an admin may read: its linkage is rebuilt through the
            // pivot resolver, which has to carry the relation authorizer too.
            BelongsToMany::make('adminTags', 'openWidgets')
                ->through(SecuredWidgetTagEntity::class)
                ->fields(Integer::make('position')->build())
                ->extractUsing(static fn(mixed $widget): array => $widget instanceof SecuredWidgetEntity
                    ? \array_values(\array_filter(\array_map(static fn(SecuredWidgetTagEntity $row): ?OpenWidgetEntity => $row->tag, ($widget->widgetTags?->toArray() ?? []))))
                    : [])
                ->withData()
                ->security(read: "is_granted('ROLE_ADMIN')"),
        ];
    }
}
