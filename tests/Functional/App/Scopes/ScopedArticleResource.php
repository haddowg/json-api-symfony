<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Scopes;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\BaseArticleResource;

/**
 * The in-memory article, including its author by default, with a delete that needs
 * `articles:admin` as well.
 */
#[AsJsonApiResource(operationScopes: ['delete' => ['articles:write', 'articles:admin']])]
final class ScopedArticleResource extends BaseArticleResource
{
    public function getDefaultIncludedRelationships(mixed $object): array
    {
        return ['author'];
    }
}
