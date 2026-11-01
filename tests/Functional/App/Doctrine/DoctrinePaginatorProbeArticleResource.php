<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\PaginatorProbeArticleResource;

/**
 * The Doctrine variant of {@see PaginatorProbeArticleResource}, mapped to
 * {@see ArticleEntity} so the paginator suite runs over real SQL.
 */
#[AsJsonApiResource(entity: ArticleEntity::class)]
final class DoctrinePaginatorProbeArticleResource extends PaginatorProbeArticleResource {}
