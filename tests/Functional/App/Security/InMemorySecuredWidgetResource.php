<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Security;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;

/**
 * The in-memory `securedWidgets` resource: a `ROLE_USER` read default with a
 * `ROLE_ADMIN` create override, the in-memory twin of the Doctrine
 * {@see \haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\Security\SecuredWidgetResource}
 * — the witness that declarative authorization (bundle ADR 0043) is
 * provider-agnostic.
 *
 * A self-referential `partner` to-one relation gives the read-gated resource a related
 * (`/{id}/partner`) and relationship (`/{id}/relationships/partner`) endpoint, so the
 * suite can prove the read gate covers those endpoints too (a read-gated resource is
 * not reachable via its relationship endpoints).
 *
 * Two further self-relations witness **per-relation security** (core ADR 0099 / bundle
 * ADR 0100) — a relation authorized independently of its parent. All three share the
 * `partnerId` backing (the linkage is identical); only the gate differs:
 *  - `publicPartner` declares `security(read: false)` — explicitly PUBLIC, so its read
 *    endpoints are reachable even unauthenticated, MORE permissive than the
 *    `ROLE_USER`-gated parent;
 *  - `adminPartner` declares `security(read: "is_granted('ROLE_ADMIN')")` — its read
 *    endpoints require an admin, MORE restrictive than the parent (a plain `ROLE_USER`
 *    may read the resource but not this relation).
 */
#[AsJsonApiResource(
    security: "is_granted('ROLE_USER')",
    securityCreate: "is_granted('ROLE_ADMIN')",
)]
final class InMemorySecuredWidgetResource extends AbstractResource
{
    public static string $type = 'securedWidgets';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            BelongsTo::make('partner', 'securedWidgets')->storedAs('partnerId'),
            BelongsTo::make('publicPartner', 'securedWidgets')->storedAs('partnerId')->security(read: false),
            BelongsTo::make('adminPartner', 'securedWidgets')->storedAs('partnerId')->security(read: "is_granted('ROLE_ADMIN')"),
        ];
    }
}
