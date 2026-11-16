<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Singleton;

use haddowg\JsonApi\OpenApi\Metadata\ActionScope;
use haddowg\JsonApi\OpenApi\Metadata\OperationKey;
use haddowg\JsonApi\OpenApi\Metadata\OperationKind;
use haddowg\JsonApi\OpenApi\Metadata\TypeMetadataInterface;
use haddowg\JsonApi\Scope\OperationScopeResolverInterface;

/**
 * Scopes for `inboxes`: `inboxes:read` to read the caller's inbox, `inboxes:write` to
 * change it, and `inboxes:list` for a collection read, which a singleton never serves.
 */
final class InboxScopes implements OperationScopeResolverInterface
{
    public function requiredScopes(?TypeMetadataInterface $type, OperationKey $operation): array
    {
        if ($operation->type !== 'inboxes') {
            return [];
        }

        return match ($operation->kind) {
            OperationKind::FetchCollection => ['inboxes:list'],
            OperationKind::FetchOne, OperationKind::FetchRelated, OperationKind::FetchRelationship => ['inboxes:read'],
            OperationKind::Action => $operation->actionScope === ActionScope::Resource ? ['inboxes:write'] : ['inboxes:list'],
            default => ['inboxes:write'],
        };
    }
}
