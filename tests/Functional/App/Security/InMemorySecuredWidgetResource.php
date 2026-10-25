<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Security;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\HasMany;
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
 * `partner` backing (the linkage is identical); only the gate differs:
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
    securityRead: "is_granted('ROLE_USER') and (object.name != 'classified' or is_granted('ROLE_ADMIN'))",
    securityList: "is_granted('ROLE_USER') and not is_granted('ROLE_NO_LIST')",
)]
final class InMemorySecuredWidgetResource extends AbstractResource
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
            Str::make('exposedPartnerName')->storedAs('name')->on('exposedPartner'),
            Str::make('adminPartnerName')->storedAs('name')->on('adminPartner'),
            BelongsTo::make('partner', 'securedWidgets'),
            BelongsTo::make('publicPartner', 'securedWidgets')->storedAs('partner')->security(read: false),
            BelongsTo::make('adminPartner', 'securedWidgets')->storedAs('partner')->security(read: "is_granted('ROLE_ADMIN')"),
            HasMany::make('partners', 'securedWidgets')->countable(),
            HasMany::make('memos', 'memos')->countable(),
            // Computed: the closure returns every memo the holder has, and the memos
            // provider's scope still decides which of them exist.
            HasMany::make('curatedMemos', 'memos')->countable()->extractUsing(
                static fn(mixed $holder): array => \is_object($holder) ? \haddowg\JsonApiBundle\Tests\Functional\App\OwnedRows\Memo::heldBy($holder) : [],
            ),
            // Relations that declare their own read permission, which replaces the
            // related type's gates: any user may read these, a reader included, and an
            // exposed partner shows even when it is "classified".
            HasMany::make('exposedPartners', 'securedWidgets')->storedAs('partners')->countable()->security(read: "is_granted('ROLE_USER')"),
            BelongsTo::make('exposedPartner', 'securedWidgets')->storedAs('partner')->security(read: "is_granted('ROLE_USER')"),
            // Only an admin may read this one, though any user may list widgets.
            HasMany::make('blockedPartners', 'securedWidgets')->storedAs('partners')->countable()->security(read: "is_granted('ROLE_ADMIN')"),
            // A bool read declares the relation and enforces nothing.
            HasMany::make('publicPartners', 'securedWidgets')->storedAs('partners')->countable()->security(read: false),
            // Hidden from everyone but an admin, so a non-admin gets a 404 at its endpoints.
            BelongsTo::make('staffPartner', 'securedWidgets')->storedAs('partner')->hidden(
                static fn(mixed $model, JsonApiRequestInterface $request): bool => $request->getHeaderLine('Authorization') !== 'Bearer admin',
            ),
        ];
    }
}
