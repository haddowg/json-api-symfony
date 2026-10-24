<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\WriteOnlyRelationArticleResource;

/**
 * The Doctrine kernel's write-only relation `articles` resource: the shared
 * {@see WriteOnlyRelationArticleResource} declaration mapped to its entity.
 */
#[AsJsonApiResource(entity: ArticleEntity::class)]
final class DoctrineWriteOnlyRelationArticleResource extends WriteOnlyRelationArticleResource {}
