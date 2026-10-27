<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Scopes;

use haddowg\JsonApi\OpenApi\Metadata\OperationKey;
use haddowg\JsonApi\OpenApi\Metadata\OperationKind;
use haddowg\JsonApi\OpenApi\Metadata\TypeMetadataInterface;
use haddowg\JsonApi\Scope\OperationScopeResolverInterface;

/**
 * `<type>:read` for a read of articles, authors or comments, `<type>:write` for any write
 * to articles. A related read of an article needs `articles:read`, plus what the relation
 * returns, which core adds.
 */
final class ArticleScopes implements OperationScopeResolverInterface
{
    private const array TYPES = ['articles', 'authors', 'comments'];

    public function requiredScopes(?TypeMetadataInterface $type, OperationKey $operation): array
    {
        $name = $operation->type;
        if ($name === null || !\in_array($name, self::TYPES, true)) {
            return [];
        }

        return match ($operation->kind) {
            OperationKind::FetchOne, OperationKind::FetchCollection, OperationKind::FetchRelated, OperationKind::FetchRelationship => [$name . ':read'],
            OperationKind::Action => [],
            default => $name === 'articles' ? ['articles:write'] : [],
        };
    }
}
