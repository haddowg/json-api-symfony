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
            // Write-only: a null read hook renders nothing on serialize while the
            // field still hydrates from the request.
            Str::make('password')->serializeUsing(fn(): ?string => null),
            // Computed (no backing column): validated but never persisted. Carries the
            // composition demos — an AtLeastOneOf alternative, a When rule, and the
            // equality CompareField against `password`.
            Str::make('passwordConfirm')
                ->computed()
                ->serializeUsing(fn(): ?string => null)
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
            HasMany::make('playlists')->type('playlists'),
            HasOne::make('library')->type('libraries'),
        ];
    }
}
