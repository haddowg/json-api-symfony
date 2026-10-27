<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Examples\MusicCatalog\Security;

use haddowg\JsonApi\OpenApi\Metadata\OperationKey;
use haddowg\JsonApi\OpenApi\Metadata\OperationKind;
use haddowg\JsonApi\OpenApi\Metadata\TypeMetadataInterface;
use haddowg\JsonApi\Scope\OperationScopeResolverInterface;

/**
 * The OAuth scopes the catalogue's writes need: `catalog:write` to change albums,
 * `library:write` to change playlists. Reads need none, since artists' reads are public
 * and a public related read cannot reach a type whose reads need a scope.
 *
 * A playlist delete and the artwork upload declare their own scopes on their attributes,
 * and the Atomic Operations endpoint's come from `json_api.atomic_operations.scopes`.
 */
final class CatalogScopes implements OperationScopeResolverInterface
{
    private const array WRITE_SCOPES = [
        'albums' => 'catalog:write',
        'playlists' => 'library:write',
    ];

    public function requiredScopes(?TypeMetadataInterface $type, OperationKey $operation): array
    {
        $scope = self::WRITE_SCOPES[$operation->type ?? ''] ?? null;
        if ($scope === null) {
            return [];
        }

        return match ($operation->kind) {
            OperationKind::Create,
            OperationKind::Update,
            OperationKind::Delete,
            OperationKind::UpdateRelationship,
            OperationKind::AddRelationship,
            OperationKind::RemoveRelationship => [$scope],
            OperationKind::Action => $operation->method === 'GET' || $operation->safety?->readOnly === true ? [] : [$scope],
            default => [],
        };
    }
}
