<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\RelationCountArticleResource;

/**
 * The Doctrine variant of {@see RelationCountArticleResource}, mapped to
 * {@see ArticleEntity}: the same custom `minComments` filter and `commentCount` sort
 * declarations, executed as real DQL by the registered Doctrine arms
 * (`SIZE(resource.comments)`), so the extensible-handler-seam assertions run
 * identically to the in-memory witness.
 */
#[AsJsonApiResource(entity: ArticleEntity::class)]
final class DoctrineRelationCountArticleResource extends RelationCountArticleResource {}
