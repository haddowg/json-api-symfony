<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ActionLinks;

use haddowg\JsonApi\OpenApi\Metadata\OperationKey;
use haddowg\JsonApi\OpenApi\Metadata\OperationKind;
use haddowg\JsonApi\OpenApi\Metadata\TypeMetadataInterface;
use haddowg\JsonApi\Scope\OperationScopeResolverInterface;

/**
 * `articles:review` for the review action and `articles:archive` for the archive action;
 * every other operation needs no scope.
 */
final class ActionLinkScopes implements OperationScopeResolverInterface
{
    public function requiredScopes(?TypeMetadataInterface $type, OperationKey $operation): array
    {
        if ($operation->kind !== OperationKind::Action) {
            return [];
        }

        return match ($operation->actionPath) {
            'review' => ['articles:review'],
            'archive' => ['articles:archive'],
            default => [],
        };
    }
}
