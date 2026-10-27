<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\ActionLinks;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Doctrine\ArticleEntity;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\BaseArticleResource;

/**
 * The Doctrine `articles` resource with its action links.
 */
#[AsJsonApiResource(entity: ArticleEntity::class)]
final class DoctrineActionLinkArticleResource extends BaseArticleResource
{
    use LinksArticleActions;
}
