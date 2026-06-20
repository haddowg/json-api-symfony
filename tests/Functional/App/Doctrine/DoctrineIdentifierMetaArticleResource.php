<?php

declare(strict_types=1);

namespace haddowg\JsonApiBundle\Tests\Functional\App\Doctrine;

use haddowg\JsonApiBundle\Attribute\AsJsonApiResource;
use haddowg\JsonApiBundle\Tests\Functional\App\Resource\IdentifierMetaArticleResource;

/**
 * The Doctrine kernel's identifier-meta `articles` resource: the shared
 * {@see IdentifierMetaArticleResource} declaration (parent-aware `identifierMeta()`
 * on `author`/`comments`) mapped to its backing entity via
 * `#[AsJsonApiResource(entity: …)]`, so the
 * {@see \haddowg\JsonApiBundle\DependencyInjection\Compiler\DoctrineEntityMapPass}
 * routes `articles` to the
 * {@see \haddowg\JsonApiBundle\DataProvider\Doctrine\DoctrineDataProvider}. The
 * `identifierMeta` resolvers read the public `id`/`name` members through core's
 * Accessor, so the same closures stamp the linkage identifier meta over real
 * managed entities.
 */
#[AsJsonApiResource(entity: ArticleEntity::class)]
final class DoctrineIdentifierMetaArticleResource extends IdentifierMetaArticleResource {}
