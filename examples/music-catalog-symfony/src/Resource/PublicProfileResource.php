<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Resource;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Examples\MusicCatalog\Entity\User;
use haddowg\JsonApiBundle\Operation\Operation;

/**
 * The `public-profiles` resource type — a curated, public-facing **second view of
 * the same {@see User} entity** the admin-only `users` resource exposes.
 *
 * This is the worked example for **one entity backing two JSON:API resource
 * types**. Both this resource and {@see UserResource} declare
 * `#[AsJsonApiResource(entity: User::class)]`: the type→entity map the bundle
 * builds only rejects one type mapping to two entities, never two types mapping to
 * one entity, so both `users` and `public-profiles` resolve the same row through
 * the same provider (the Doctrine reference here, the in-memory witness in the
 * bundle's dual-provider conformance suite). A type is always supplied by context —
 * the route for primary data, a relation's `make()` type declaration for linkage —
 * so the same `User` is rendered as `users` under `/admin/users/1` and as
 * `public-profiles` under `/public-profiles/1`, each its own fields and serializer.
 *
 * Where `users` is admin-only (`server: 'admin'`) and exposes the full record —
 * email, birth date, last-seen IP, the write-only credential — this view lives on
 * the **default** server and renders only what a public profile should: the
 * display name. The private columns are simply never declared here, so no sparse
 * fieldset, include, or relationship can resurface them; the curation is the field
 * inventory, not a runtime filter.
 *
 * It is **read-only**: the operation allow-list omits create/update/delete (a
 * public profile is mutated through the `users` admin resource), so only
 * `GET /public-profiles` and `GET /public-profiles/{id}` are routed. The relation
 * that targets it — {@see PlaylistResource}'s `publicOwner` — therefore renders
 * `public-profiles` linkage and includes the curated resource, without exposing a
 * write surface for it.
 *
 * See `docs/resources.md` ("One entity, two resource types") for the pattern.
 */
#[AsJsonApiResource(
    entity: User::class,
    operations: [Operation::FetchCollection, Operation::FetchOne],
    tags: ['Library'],
)]
final class PublicProfileResource extends AbstractResource
{
    public static string $type = 'public-profiles';

    public function fields(): array
    {
        return [
            Id::make(),
            // The ONLY public attribute. The User entity's email / birthDate /
            // preferences / lastSeenIp / password columns are deliberately NOT
            // declared, so this view can never render them — the same row, a
            // strictly narrower projection than `users`.
            Str::make('displayName')->sortable(),
        ];
    }

    /**
     * Object-aware getType so this resource can participate as a relation target
     * resolved by class: a real {@see User} is a `public-profiles` here.
     */
    public function getType(mixed $object): string
    {
        return $object instanceof User ? 'public-profiles' : '';
    }
}
