<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Scopes;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\ArticleEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\BaseArticleResource;

/**
 * {@see ScopedArticleResource} over the Doctrine article entity.
 */
#[AsJsonApiResource(entity: ArticleEntity::class, operationScopes: ['delete' => ['articles:write', 'articles:admin']])]
final class DoctrineScopedArticleResource extends BaseArticleResource
{
    public function getDefaultIncludedRelationships(mixed $object): array
    {
        return ['author'];
    }
}
