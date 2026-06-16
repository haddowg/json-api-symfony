<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\ConstrainedFilterArticleResource;

/**
 * The Doctrine variant of {@see ConstrainedFilterArticleResource}: the same
 * constraint-bearing `filters()` declaration mapped to {@see ArticleEntity}, so
 * the filter-value-constraint conformance assertions run as real DQL — a
 * mistyped `filter[id]=banana` is rejected with a clean `400` before any query
 * runs, never reaching the data layer.
 */
#[AsJsonApiResource(entity: ArticleEntity::class)]
final class DoctrineConstrainedFilterArticleResource extends ConstrainedFilterArticleResource {}
