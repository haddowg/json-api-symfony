<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\SingleTargetMorphArticleResource;

#[AsJsonApiResource(entity: ArticleEntity::class)]
final class DoctrineSingleTargetMorphArticleResource extends SingleTargetMorphArticleResource {}
