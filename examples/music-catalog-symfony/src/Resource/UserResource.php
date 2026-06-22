<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Resource;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Constraint\Comparison;
use haddowg\JsonApi\Resource\Constraint\MinLength;
use haddowg\JsonApi\Resource\Constraint\Pattern;
use haddowg\JsonApi\Resource\Field\ArrayHash;
use haddowg\JsonApi\Resource\Field\Date;
use haddowg\JsonApi\Resource\Field\Email;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Field\HasOne;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Ip;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Examples\MusicCatalog\Entity\User;
use haddowg\JsonApiBundle\Validation\Constraint\UniqueEntity;

/**
 * The `users` resource type, mapped to its backing {@see User} entity.
 *
 * It is the **admin-only multi-server witness** (ADR 0034): `server: 'admin'`
 * exposes it on the named `admin` server alone (mounted under `/admin`), so
 * `/users` 404s on the default surface while `/admin/users` resolves.
 *
 * Field/relation declarations are re-themed verbatim from core's in-memory
 * {@see https://github.com/haddowg/json-api/blob/main/examples/music-catalog/src/Resource/UserResource.php UserResource}:
 * format-subtype fields ({@see Email}, {@see Ip}), a dynamic-key {@see ArrayHash},
 * a write-only `password`, and the validation-composition trio on `passwordConfirm`
 * (an {@see \haddowg\JsonApi\Resource\Constraint\AtLeastOneOf}, a conditional
 * {@see \haddowg\JsonApi\Resource\Constraint\When}, and a non-directional equality
 * {@see \haddowg\JsonApi\Resource\Constraint\CompareField}). Beyond core, `email`
 * additionally carries a {@see UniqueEntity} entity-level rule — the post-hydration
 * seam that queries this repository through `symfony/doctrine-bridge` to reject a
 * duplicate before commit.
 */
#[AsJsonApiResource(entity: User::class, server: 'admin')]
final class UserResource extends AbstractResource
{
    public static string $type = 'users';

    public function fields(): array
    {
        return [
            Id::make(),
            // Email::make() pre-attaches a (lax) EmailFormat; ->strict() reconciles
            // that to a single strict EmailFormat. The UniqueEntity is the
            // entity-level rule (queried against the repository post-hydration).
            Email::make('email')->required()->strict()->constrain(new UniqueEntity(['email'])),
            Str::make('displayName')->required(),
            Date::make('birthDate')->nullable(),
            // A dynamic-key JSON object (vs Map's declared columns); sorted by key on
            // serialization for a stable wire shape.
            ArrayHash::make('preferences')->minProperties(0)->maxProperties(20)->sortKeys(),
            Ip::make('lastSeenIp')->nullable(),
            // A genuine write-only credential (Tier-0 G18, core ADR 0060): accepted on
            // BOTH create and update and still validated (required on create, min 8) —
            // but skipped in the attribute render, so it appears on NO read (single,
            // collection, included, related) and a `fields[users]=password` cannot
            // resurrect it. The exact inverse of `readOnly()`; no `serializeUsing`
            // null-hook is needed.
            Str::make('password')->writeOnly()->minLength(8)->requiredOnCreate(),
            // Computed (no backing column) AND write-only: validated but never
            // persisted, never echoed. Carries the composition demos — an AtLeastOneOf
            // alternative, a When rule, and the equality CompareField against
            // `password`.
            Str::make('passwordConfirm')
                ->computed()
                ->writeOnly()
                ->atLeastOneOf(
                    new MinLength(8),
                    new Pattern('^.*[0-9].*$'),
                )
                ->when(
                    static fn(mixed $value): bool => $value !== null && $value !== '',
                    static function (Str $field): void {
                        $field->minLength(8);
                    },
                )
                ->compareWith('password', Comparison::EqualTo),

            // Default relation reader: `playlists` reads the OneToMany and `library`
            // the OneToOne straight off the entity associations.
            HasMany::make('playlists', 'playlists'),
            HasOne::make('library', 'libraries'),
        ];
    }

    /**
     * Include safeguard C (bundle ADR 0037): the full dotted include paths a client
     * may request when `users` is the request's root. A user may compound their
     * playlists, each playlist's owner, and their library — but NOT a playlist's
     * tracks, even though `tracks` is freely includable when `playlists` is the
     * request's own root (`GET /playlists/{id}?include=tracks`). This is the
     * headline a per-relation `cannotBeIncluded()` cannot express: a path forbidden
     * only when reached as a NESTED path from this parent. The whitelist is
     * evaluated once against this root resource and governs the whole nested tree,
     * so `GET /admin/users/1?include=playlists.tracks` is a 400 while
     * `?include=playlists` and `?include=playlists.owner` are allowed. `null` (the
     * default) would leave every includable path open.
     *
     * @return list<string>
     */
    public function getAllowedIncludePaths(): array
    {
        return ['playlists', 'playlists.owner', 'library'];
    }
}
