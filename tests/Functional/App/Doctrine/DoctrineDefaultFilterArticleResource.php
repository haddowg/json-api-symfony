<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\DefaultFilterArticleResource;

/**
 * The Doctrine variant of {@see DefaultFilterArticleResource}: the same
 * default-bearing `filters()` declaration mapped to {@see ArticleEntity}, so
 * the filter-default conformance assertions run as real DQL.
 */
#[AsJsonApiResource(entity: ArticleEntity::class)]
final class DoctrineDefaultFilterArticleResource extends DefaultFilterArticleResource {}
