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
            BelongsTo::make('partner')->type('securedWidgets')->storedAs('partnerId'),
        ];
    }
}
